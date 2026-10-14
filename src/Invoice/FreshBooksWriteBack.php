<?php

declare(strict_types=1);

namespace App\Invoice;

use App\Audit\AuditLog;
use App\Connector\FreshBooks\FreshBooksConnector;
use App\Messenger\Message\FreshBooksWriteBackMessage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Zapis zwrotny po przyjęciu w KSeF (docs/modules/freshbooks.md, „Po przyjęciu: zapis zwrotny i doręczenie”): `PUT`
 * faktury FreshBooks z `action_mark_as_sent` = true, `create_date` = P_1 i linią z numerem KSeF dopisaną do `notes`.
 * Najpierw odczyt: numer już w `notes` → bez zapisu. To nie jest zapis w urzędzie: handler działa na transporcie `reads`
 * z retry i backoffem, poza `official_writes`; `invoice.written_back_at` ustawia raz.
 */
#[AsMessageHandler]
final readonly class FreshBooksWriteBack
{
    public const NOTE = 'KSeF: %s. Faktura wystawiona w KSeF; ten widok nie jest fakturą. Wizualizacja z kodem QR: PDF w e-mailu / Invoice issued in KSeF; this view is not the invoice. See the PDF with QR code in the e-mail.';
    public const ACTION = 'invoice.written_back';

    public function __construct(private Connection $db, private FreshBooksConnector $freshbooks, private AuditLog $audit, private ClockInterface $clock) {}

    public function __invoke(FreshBooksWriteBackMessage $message): void
    {
        $this->writeBack($message->invoiceId);
    }

    /** @return string `written`, `already_present`, `already_written` albo `not_applicable` */
    public function writeBack(string $invoiceId): string
    {
        $invoice = $this->db->fetchAssociative("SELECT environment, status, kind, freshbooks_id, ksef_number, issue_date, written_back_at FROM invoice WHERE id=? AND direction='sale'", [$invoiceId]);
        if ($invoice === false || $invoice['status'] !== 'accepted' || $invoice['kind'] !== 'VAT' || !is_string($invoice['freshbooks_id']) || !is_string($invoice['ksef_number']) || !is_string($invoice['issue_date']) || !is_string($invoice['environment'])) {
            return 'not_applicable';
        }
        if ($invoice['written_back_at'] !== null) {
            return 'already_written';
        }
        $source = $this->freshbooks->invoice($invoice['freshbooks_id']);
        if ($source === null) {
            return 'not_applicable';
        }
        $notes = $source->notes ?? '';
        $result = 'already_present';
        if (!str_contains($notes, $invoice['ksef_number'])) {
            $line = sprintf(self::NOTE, $invoice['ksef_number']);
            $this->freshbooks->updateInvoice($invoice['freshbooks_id'], [
                'action_mark_as_sent' => true,
                'create_date' => $invoice['issue_date'],
                'notes' => trim($notes) === '' ? $line : rtrim($notes) . "\n\n" . $line,
            ]);
            $result = 'written';
        }
        $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
        $changed = $this->db->executeStatement('UPDATE invoice SET written_back_at=?, updated_at=? WHERE id=? AND written_back_at IS NULL', [$now, $now, $invoiceId]);
        if ($changed === 1) {
            $this->audit->append(self::ACTION, ['result' => $result,'freshbooks_id' => $invoice['freshbooks_id']], $invoice['environment'], 'invoice', $invoiceId);
        }
        return $result;
    }
}
