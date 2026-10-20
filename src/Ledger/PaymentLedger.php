<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Config\ConnectorConfiguration;
use App\Connector\FreshBooks\FreshBooksPayment;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Płatności z FreshBooks w `payment` (docs/modules/freshbooks.md, „Płatności 2026”): wiersz na płatność, ponowny odczyt
 * nic nie dubluje. Przypisanie najpierw po `freshbooks_invoice_id` do `invoice.freshbooks_id`, potem po numerze faktury
 * do wiersza `regular` ewidencji dowolnego `source`. Niedopasowane sprawdza ponownie każdy przebieg.
 */
final readonly class PaymentLedger
{
    public function __construct(private Connection $db, private ClockInterface $clock, private ConnectorConfiguration $configuration) {}

    /** `new`, `known` albo `deleted` (płatność usunięta we FreshBooks i nieznana kokpitowi). */
    public function upsert(FreshBooksPayment $payment): string
    {
        $environment = $this->configuration->environment();
        $known = $this->db->fetchOne('SELECT id FROM payment WHERE environment=? AND freshbooks_payment_id=?', [$environment, $payment->id]);
        if (is_string($known)) {
            return 'known';
        }
        if ($payment->deleted) {
            return 'deleted';
        }
        $now = $this->now();
        $this->db->insert('payment', [
            'id' => UuidV7::generate($this->clock->now()),'environment' => $environment,'freshbooks_payment_id' => $payment->id,'freshbooks_invoice_id' => $payment->invoiceId,
            'paid_on' => $payment->paidOn,'amount' => $payment->amount,'currency' => $payment->currency,'gateway' => $payment->gateway,'created_at' => $now,'updated_at' => $now,
        ]);
        return 'new';
    }

    /**
     * Przypisanie do faktur z `freshbooks_id`; zwraca `freshbooks_invoice_id` płatności, które nadal czekają.
     *
     * @return list<string>
     */
    public function matchByInvoice(): array
    {
        $environment = $this->configuration->environment();
        $this->db->executeStatement(
            "UPDATE payment p SET invoice_id=i.id, matched_at=?, updated_at=? FROM invoice i
             WHERE p.environment=? AND p.matched_at IS NULL AND i.environment=p.environment AND i.direction='sale' AND i.freshbooks_id=p.freshbooks_invoice_id
             AND i.status IN ('accepted','corrected','superseded')",
            [$this->now(), $this->now(), $environment],
        );
        $waiting = $this->db->fetchFirstColumn('SELECT DISTINCT freshbooks_invoice_id FROM payment WHERE environment=? AND matched_at IS NULL AND freshbooks_invoice_id IS NOT NULL ORDER BY 1', [$environment]);
        return array_values(array_filter($waiting, 'is_string'));
    }

    /** Przypisanie po numerze faktury FreshBooks do wiersza `regular`; liczba przypisanych płatności. */
    public function matchByNumber(string $freshbooksInvoiceId, string $invoiceNumber): int
    {
        $environment = $this->configuration->environment();
        return $this->db->transactional(function () use ($environment, $freshbooksInvoiceId, $invoiceNumber): int {
            $count = 0;
            $payments = $this->db->fetchAllAssociative('SELECT id,paid_on FROM payment WHERE environment=? AND freshbooks_invoice_id=? AND matched_at IS NULL FOR UPDATE', [$environment, $freshbooksInvoiceId]);
            foreach ($payments as $payment) {
                // Najnowszy rok nie późniejszy niż zapłata: faktura z grudnia zapłacona w styczniu wskazuje wiersz z grudnia.
                $entry = $this->db->fetchOne(
                    "SELECT id FROM revenue_entry WHERE environment=? AND document_number=? AND kind='regular' AND revenue_date<=? ORDER BY year DESC, lp LIMIT 1",
                    [$environment, $invoiceNumber, $payment['paid_on']],
                );
                if (is_string($entry)) {
                    $this->db->update('payment', ['revenue_entry_id' => $entry,'matched_at' => $this->now(),'updated_at' => $this->now()], ['id' => $payment['id']]);
                    $count++;
                }
            }
            return $count;
        });
    }

    /**
     * Przypisane płatności w walucie bez różnicy kursowej; `$year` zawęża do roku `paid_on`.
     *
     * @return list<string>
     */
    public function awaitingFx(?int $year = null): array
    {
        $ids = $this->db->fetchFirstColumn(
            "SELECT id FROM payment WHERE environment=? AND matched_at IS NOT NULL AND fx_posted_at IS NULL AND currency<>'PLN' AND (?::int IS NULL OR extract(year FROM paid_on)=?::int) ORDER BY paid_on, id",
            [$this->configuration->environment(), $year, $year],
        );
        return array_values(array_filter($ids, 'is_string'));
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s.uP');
    }
}
