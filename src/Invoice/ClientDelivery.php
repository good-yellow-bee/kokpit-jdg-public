<?php

declare(strict_types=1);

namespace App\Invoice;

use App\Audit\AuditLog;
use App\Connector\FreshBooks\FreshBooksConnector;
use App\Connector\FreshBooks\FreshBooksException;
use App\Connector\Mail\GuardedMailTransport;
use App\Http\ConnectorException;
use App\Rate\RateLimitExceededException;
use App\Storage\FileStore;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * „Doręcz klientowi” (docs/modules/freshbooks.md, „Doręczenie klientowi”; D-31): e-mail konektorem `mail` z PDF z KOD I
 * i linkiem do płatności FreshBooks, synchronicznie w żądaniu panelu, poza Messenger. Adres i osobę kontaktową kokpit
 * czyta z FreshBooks w chwili wysyłki, bez kopii. Jedno wywołanie to jedna próba: bez automatycznego ponowienia; błąd
 * wraca do panelu jako `ClientDeliveryFailed`. Udane doręczenie zapisuje `invoice.delivered_to_client` w `audit_log`.
 */
final readonly class ClientDelivery
{
    public const ACTION = 'invoice.delivered_to_client';

    public function __construct(
        private Connection $db,
        private FreshBooksConnector $freshbooks,
        private InvoicePdf $pdf,
        private FileStore $files,
        private GuardedMailTransport $mail,
        private AuditLog $audit,
        private ClockInterface $clock,
        private string $from,
    ) {}

    /** @return string chwila doręczenia (UTC, ISO 8601) */
    public function deliver(string $invoiceId, string $userId): string
    {
        $invoice = $this->db->fetchAssociative("SELECT environment, status, freshbooks_id, ksef_number, invoice_number FROM invoice WHERE id=? AND direction='sale'", [$invoiceId]);
        if ($invoice === false || $invoice['status'] !== 'accepted' || !is_string($invoice['ksef_number']) || !is_string($invoice['environment']) || !is_string($invoice['invoice_number'])) {
            throw new ClientDeliveryFailed('not_accepted');
        }
        if (!is_string($invoice['freshbooks_id'])) {
            throw new ClientDeliveryFailed('freshbooks_missing');
        }
        try {
            $source = $this->freshbooks->invoice($invoice['freshbooks_id']) ?? throw new ClientDeliveryFailed('freshbooks_missing');
            $client = $source->clientId === null ? null : $this->freshbooks->client($source->clientId);
            if ($client === null || $client->email === null) {
                throw new ClientDeliveryFailed('client_email_missing');
            }
            $link = $this->freshbooks->shareLink($invoice['freshbooks_id']) ?? throw new ClientDeliveryFailed('share_link_missing');
        } catch (FreshBooksException|ConnectorException|RateLimitExceededException) {
            throw new ClientDeliveryFailed('freshbooks_failed');
        }
        $fileId = $this->pdf->store($invoiceId);
        $number = $invoice['invoice_number'];
        $ksef = $invoice['ksef_number'];
        $greeting = $client->contact === null ? 'Dzień dobry,' : sprintf('Dzień dobry %s,', $client->contact);
        $email = (new Email())
            ->from($this->from)
            ->to(new Address($client->email, $client->contact ?? ''))
            ->subject(sprintf('Faktura %s / Invoice %s', $number, $number))
            ->text(implode("\n", [
                $greeting,
                '',
                sprintf('w załączniku faktura %s wystawiona w KSeF, numer KSeF: %s.', $number, $ksef),
                'Faktura z kodem QR (KOD I) jest w pliku PDF. Przy przelewie podaj numer KSeF.',
                sprintf('Płatność: %s', $link),
                '',
                sprintf('Please find attached invoice %s issued in KSeF, KSeF number: %s.', $number, $ksef),
                'The invoice with its QR verification code is in the PDF. Please quote the KSeF number with your bank transfer.',
                sprintf('Payment: %s', $link),
            ]))
            ->attach($this->files->read($fileId), sprintf('faktura-%s.pdf', preg_replace('/[^A-Za-z0-9_-]+/', '-', $number)), 'application/pdf');
        try {
            $sent = $this->mail->send($email);
        } catch (\RuntimeException) {
            // Błąd SMTP, limit `mail.send` albo brak poświadczenia: jedna próba, wynik w panelu (D-31).
            throw new ClientDeliveryFailed('mail_failed');
        }
        if ($sent === null) {
            throw new ClientDeliveryFailed('mail_disabled');
        }
        $this->audit->append(self::ACTION, ['ksef_number' => $ksef,'pdf_file_id' => $fileId], $invoice['environment'], 'invoice', $invoiceId, $userId);
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
    }

    /** Chwila poprzedniego udanego doręczenia; panel pokazuje ją przy ponowieniu. */
    public function lastDelivery(string $invoiceId): ?string
    {
        $at = $this->db->fetchOne('SELECT max(occurred_at) FROM audit_log WHERE action=? AND target_type=? AND target_id=?', [self::ACTION, 'invoice', $invoiceId]);
        return is_string($at) ? $at : null;
    }
}
