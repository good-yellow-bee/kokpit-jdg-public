<?php

declare(strict_types=1);

namespace App\Connector\FreshBooks;

use App\Config\ConnectorConfiguration;
use App\Invoice\SalesReadiness;
use App\Ledger\FreshBooksLedger;
use App\Ledger\FxDifference;
use App\Ledger\PaymentLedger;
use App\Ledger\Subject1Ledger;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Strumienie `invoices` (`freshbooks.poll_invoices`) i `payments` (`freshbooks.poll_payments`) z kursorem w `sync_cursor`
 * (docs/modules/freshbooks.md, „Polling i kursory”): pierwszy przebieg to backfill od `backfill_from`, kolejne czytają od
 * `last_value` minus 1 dzień, bo filtr `updated_min` przyjmuje tylko datę. `last_value` przesuwa się w tej samej
 * transakcji co zapisane wiersze i nie wyprzedza najwcześniejszej zmiany faktury oczekującej na odpowiednik z KSeF.
 */
final readonly class FreshBooksSync
{
    /** Początek backfillu obu strumieni (docs/modules/freshbooks.md): import 2026. */
    public const BACKFILL_FROM = '2026-01-01';

    public function __construct(
        private FreshBooksConnector $api,
        private FreshBooksLedger $ledger,
        private PaymentLedger $payments,
        private FxDifference $fx,
        private Subject1Ledger $subject1,
        private Connection $db,
        private BusinessDate $businessDate,
        private ClockInterface $clock,
        private ConnectorConfiguration $configuration,
        private ?SalesReadiness $readiness = null,
    ) {}

    /** @return array<string,int> liczba faktur per wynik klasyfikacji */
    public function pollInvoices(): array
    {
        $cursor = $this->cursor('invoices');
        $start = $this->businessDate->today();
        $invoices = $this->api->invoices(['updated_min' => $this->updatedMin($cursor)]);
        $write = $this->subject1->autoEntriesEnabled();
        $transition = $this->ledger->transitionDay();
        return $this->db->transactional(function () use ($cursor, $start, $invoices, $write, $transition): array {
            $counts = [];
            $holdback = $start;
            foreach ($invoices as $invoice) {
                $outcome = $this->ledger->classify($invoice, $write, $transition);
                $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
                // Znacznik gotowości (F3.2): `draft` → `ready` i prośba o zatwierdzenie; nic nie wysyła do KSeF.
                $this->readiness?->observe($invoice, $outcome);
                if (in_array($outcome, FreshBooksLedger::WAITING, true) && $invoice->updatedOn < $holdback) {
                    $holdback = $invoice->updatedOn;
                }
            }
            $this->advance($cursor, $holdback);
            ksort($counts);
            return $counts;
        });
    }

    /** @return array<string,int> liczba płatności per wynik zapisu, przypisania i różnicy kursowej */
    public function pollPayments(): array
    {
        $cursor = $this->cursor('payments');
        $start = $this->businessDate->today();
        $payments = $this->api->payments(['updated_min' => $this->updatedMin($cursor)]);
        $counts = $this->db->transactional(function () use ($cursor, $start, $payments): array {
            $counts = [];
            foreach ($payments as $payment) {
                $outcome = $this->payments->upsert($payment);
                $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
            }
            $this->advance($cursor, $start);
            return $counts;
        });
        $counts['matched'] = $this->match();
        if ($this->subject1->autoEntriesEnabled()) {
            foreach ($this->payments->awaitingFx() as $id) {
                $outcome = 'fx_' . $this->fx->post($id);
                $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
            }
        }
        ksort($counts);
        return $counts;
    }

    /**
     * Odtworzenie roku (`app:ledger:import freshbooks <rok>`): faktury z `create_date` w roku z zapisem wierszy niezależnie
     * od `ledger.auto_entries_enabled` (K_2 = dzień odtworzenia, D-23), potem różnice kursowe przypisanych płatności roku.
     *
     * @return array<string,int>
     */
    public function reconstruct(int $year): array
    {
        $invoices = $this->api->invoices(['date_min' => sprintf('%04d-01-01', $year),'date_max' => sprintf('%04d-12-31', $year)]);
        $transition = $this->ledger->transitionDay();
        $counts = [];
        foreach ($invoices as $invoice) {
            $outcome = $this->ledger->classify($invoice, true, $transition);
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        }
        $counts['matched'] = $this->match();
        foreach ($this->payments->awaitingFx($year) as $id) {
            $outcome = 'fx_' . $this->fx->post($id);
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    /** Przypisanie płatności: po `freshbooks_id`, potem po numerze faktury odczytanym z FreshBooks. */
    private function match(): int
    {
        $before = $this->db->fetchOne('SELECT count(*) FROM payment WHERE environment=? AND matched_at IS NOT NULL', [$this->configuration->environment()]);
        $count = 0;
        foreach ($this->payments->matchByInvoice() as $invoiceId) {
            $invoice = $this->api->invoice($invoiceId);
            if ($invoice !== null) {
                $count += $this->payments->matchByNumber($invoiceId, $invoice->number);
            }
        }
        $after = $this->db->fetchOne('SELECT count(*) FROM payment WHERE environment=? AND matched_at IS NOT NULL', [$this->configuration->environment()]);
        return (is_numeric($after) ? (int) $after : 0) - (is_numeric($before) ? (int) $before : 0);
    }

    /** @return array{id:string,last_value:?string,backfill_from:?string,backfill_done_at:?string} */
    private function cursor(string $stream): array
    {
        $environment = $this->configuration->environment();
        $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
        $this->db->executeStatement(
            "INSERT INTO sync_cursor (id,environment,connector,stream,backfill_from,created_at,updated_at) VALUES (?,?,'freshbooks',?,?,?,?) ON CONFLICT (environment,connector,stream) DO NOTHING",
            [(string) UuidV7::generate($this->clock->now()), $environment, $stream, self::BACKFILL_FROM, $now, $now],
        );
        $this->db->executeStatement("UPDATE sync_cursor SET last_run_at=?, updated_at=? WHERE environment=? AND connector='freshbooks' AND stream=?", [$now, $now, $environment, $stream]);
        $row = $this->db->fetchAssociative("SELECT id,last_value,backfill_from,backfill_done_at FROM sync_cursor WHERE environment=? AND connector='freshbooks' AND stream=?", [$environment, $stream]);
        if ($row === false || !is_string($row['id'])) {
            throw new \LogicException('Brak kursora FreshBooks.');
        }
        $text = static fn(mixed $value): ?string => is_string($value) ? $value : null;
        return ['id' => $row['id'],'last_value' => $text($row['last_value']),'backfill_from' => $text($row['backfill_from']),'backfill_done_at' => $text($row['backfill_done_at'])];
    }

    /** @param array{id:string,last_value:?string,backfill_from:?string,backfill_done_at:?string} $cursor */
    private function updatedMin(array $cursor): string
    {
        if ($cursor['backfill_done_at'] === null || $cursor['last_value'] === null) {
            return $cursor['backfill_from'] ?? self::BACKFILL_FROM;
        }
        return (new \DateTimeImmutable($cursor['last_value']))->modify('-1 day')->format('Y-m-d');
    }

    /** @param array{id:string,last_value:?string,backfill_from:?string,backfill_done_at:?string} $cursor */
    private function advance(array $cursor, string $lastValue): void
    {
        // Kursor nie cofa się. Faktura oczekująca ma zmianę najwyżej dzień przed `last_value`, więc okno minus 1 dzień ją obejmuje.
        $value = $cursor['backfill_done_at'] !== null && $cursor['last_value'] !== null && $cursor['last_value'] > $lastValue ? $cursor['last_value'] : $lastValue;
        $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
        $this->db->executeStatement('UPDATE sync_cursor SET last_value=?, last_success_at=?, backfill_done_at=coalesce(backfill_done_at, ?), updated_at=? WHERE id=?', [$value, $now, $now, $now, $cursor['id']]);
    }
}
