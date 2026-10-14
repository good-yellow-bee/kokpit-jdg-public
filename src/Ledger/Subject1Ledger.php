<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Fx\FxRate;
use App\Fx\FxRateProvider;
use App\Fx\FxRateUnavailable;
use App\Invoice\Fa3Document;
use App\Invoice\Fa3Line;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use App\Time\BusinessDate;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Sprzedaż z KSeF Subject1 w ewidencji (F2.2): `invoice.revenue_date`, `fx_pit_rate_id` i stawki pozycji,
 * a po włączeniu `ledger.auto_entries_enabled` także wiersze `regular` (docs/modules/ewidencja.md, „Wpis do 20. dnia”).
 * Brak daty przychodu, kursu albo stawki zostawia fakturę bez wpisu; widok braków pokazuje ją w panelu.
 */
final readonly class Subject1Ledger
{
    public function __construct(
        private Connection $db,
        private FxRateProvider $rates,
        private SettingStore $settings,
        private BusinessDate $businessDate,
        private ClockInterface $clock,
        private RevenueEntryWriter $writer,
    ) {}

    public function autoEntriesEnabled(): bool
    {
        return $this->settings->value(SettingKeys::LEDGER_AUTO_ENTRIES) === true;
    }

    /** Wywołanie z importu Subject1: pola faktury zawsze, wiersze tylko po przełączeniu. */
    public function onImported(string $invoiceId): Subject1LedgerResult
    {
        return $this->apply($invoiceId, $this->autoEntriesEnabled());
    }

    /**
     * Odtworzenie roku (`app:ledger:import ksef_subject1 <rok>`): każda przyjęta faktura `VAT` z Subject1 z datą przychodu
     * w roku; wiersze tylko dla faktur bez wpisu w żadnym `source`.
     *
     * @return array<string,int> liczba faktur per wynik
     */
    public function reconstruct(int $year, string $environment): array
    {
        $ids = $this->db->fetchFirstColumn(
            "SELECT id FROM invoice WHERE environment=? AND direction='sale' AND kind='VAT' AND ksef_number IS NOT NULL AND status IN ('accepted','corrected','superseded')
             AND (revenue_date IS NULL AND extract(year FROM issue_date)=? OR extract(year FROM revenue_date)=?) ORDER BY revenue_date NULLS LAST, issue_date, invoice_number",
            [$environment, $year, $year],
        );
        $counts = [];
        foreach ($ids as $id) {
            $outcome = $this->apply(self::str($id), true)->outcome;
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    /**
     * Pola faktury i, gdy `$write`, wiersze `regular`. Odtworzenie (`app:ledger:import ksef_subject1`) woła z `$write` = true
     * niezależnie od przełączenia; K_2 to wtedy dzień odtworzenia (D-23).
     */
    public function apply(string $invoiceId, bool $write): Subject1LedgerResult
    {
        return $this->db->transactional(function () use ($invoiceId, $write): Subject1LedgerResult {
            $invoice = $this->db->fetchAssociative(
                "SELECT id,kind,invoice_number,ksef_number,currency,revenue_date,fx_pit_rate_id,buyer_snapshot
                 FROM invoice WHERE id=? AND direction='sale' AND status IN ('accepted','corrected','superseded') FOR UPDATE",
                [$invoiceId],
            );
            if ($invoice === false) {
                throw new \DomainException('Brak przyjętej faktury sprzedaży.');
            }
            if ($invoice['kind'] !== 'VAT') {
                // KOR i faktury zaliczkowe idą przez zadanie w panelu (docs/modules/ewidencja.md, „Korekty”).
                return new Subject1LedgerResult($invoiceId, 'not_vat');
            }
            $lines = $this->db->fetchAllAssociative('SELECT id,description,net_amount,ryczalt_rate_pct FROM invoice_line WHERE invoice_id=? AND NOT state_before ORDER BY line_no', [$invoiceId]);
            // Datę przychodu zapisuje import z pól FA(3); pusta oznacza różne P_6A i datę wpisywaną w panelu.
            $revenueDate = is_string($invoice['revenue_date']) ? $invoice['revenue_date'] : null;
            if ($revenueDate === null) {
                return new Subject1LedgerResult($invoiceId, 'revenue_date_manual');
            }
            $updates = [];
            $rate = null;
            $currency = self::str($invoice['currency']);
            if ($currency !== 'PLN') {
                if (is_string($invoice['fx_pit_rate_id'])) {
                    $rate = $this->rate(self::str($invoice['fx_pit_rate_id']));
                } else {
                    try {
                        $rate = $this->rates->getRate($currency, $revenueDate);
                        $updates['fx_pit_rate_id'] = $rate->id;
                    } catch (FxRateUnavailable) {
                        $rate = null;
                    }
                }
            }
            if ($updates !== []) {
                $this->db->update('invoice', $updates + ['updated_at' => $this->clock->now()->format('Y-m-d\TH:i:s.uP')], ['id' => $invoiceId]);
            }
            $map = ServiceMap::fromSetting($this->settings->value(SettingKeys::RYCZALT_SERVICE_MAP, $revenueDate));
            $byRate = [];
            $missing = false;
            foreach ($lines as $line) {
                $ratePct = is_string($line['ryczalt_rate_pct']) ? $line['ryczalt_rate_pct'] : null;
                if ($ratePct === null) {
                    // Subject1 bez FreshBooks: `p7_match` na P_7, potem `default` (docs/tax-rules.md, „Dopasowanie pozycji do wpisu”).
                    $entry = $map->match(null, is_string($line['description']) ? $line['description'] : null);
                    if ($entry === null) {
                        $missing = true;
                        continue;
                    }
                    $ratePct = $entry['rate_pct'];
                    $this->db->update('invoice_line', ['service_type' => $entry['service_type'],'pkwiu' => $entry['pkwiu'],'ryczalt_rate_pct' => $ratePct,'updated_at' => $this->clock->now()->format('Y-m-d\TH:i:s.uP')], ['id' => $line['id']]);
                }
                $key = (string) BigDecimal::of($ratePct)->toScale(2);
                $byRate[$key] = (string) BigDecimal::of($byRate[$key] ?? '0')->plus(self::str($line['net_amount']))->toScale(2);
            }
            if ($missing) {
                return new Subject1LedgerResult($invoiceId, 'rate_missing');
            }
            if ($currency !== 'PLN' && $rate === null) {
                return new Subject1LedgerResult($invoiceId, 'fx_rate_pending');
            }
            if (!$write) {
                return new Subject1LedgerResult($invoiceId, 'fields_only');
            }
            [$country, $taxId] = self::counterparty(is_string($invoice['buyer_snapshot']) ? $invoice['buyer_snapshot'] : '{}');
            $result = $this->writer->write(new RevenueDocument('ksef_subject1', $invoiceId, self::str($invoice['invoice_number']), is_string($invoice['ksef_number']) ? $invoice['ksef_number'] : null, $country, $taxId, $revenueDate, $currency, $rate, $byRate));
            return new Subject1LedgerResult($invoiceId, $result->duplicate() ? 'duplicate' : 'written', $result);
        });
    }

    /**
     * Data przychodu Subject1 (docs/tax-rules.md, „Data przychodu według źródła”): `OkresFa` → P_6_Do (art. 14 ust. 1e);
     * P_6 albo jednakowe P_6A, najpóźniej P_1 (art. 14 ust. 1c); bez dat usługi P_1; różne P_6A → null (zadanie w panelu).
     */
    public static function revenueDate(Fa3Document $document): ?string
    {
        if ($document->periodEnd !== null) {
            return $document->periodEnd;
        }
        $service = $document->serviceDate;
        if ($service === null) {
            $lines = array_filter($document->lines, static fn(Fa3Line $line): bool => !$line->stateBefore);
            $unique = array_values(array_unique(array_map(static fn(Fa3Line $line): ?string => $line->serviceDate, $lines)));
            if ($unique === [] || $unique === [null]) {
                return $document->issueDate;
            }
            if (count($unique) !== 1 || $unique[0] === null) {
                return null;
            }
            $service = $unique[0];
        }
        return $service < $document->issueDate ? $service : $document->issueDate;
    }

    /** @return array{?string,?string} K_6 i K_7 z `buyer_snapshot` */
    public static function counterparty(string $snapshot): array
    {
        $buyer = json_decode($snapshot, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($buyer)) {
            return [null, null];
        }
        $text = static fn(string $key): ?string => isset($buyer[$key]) && is_string($buyer[$key]) && $buyer[$key] !== '' ? $buyer[$key] : null;
        if ($text('nip') !== null) {
            return ['PL', $text('nip')];
        }
        if ($text('eu_vat_number') !== null && $text('eu_vat_prefix') !== null) {
            // Prefiks VAT Grecji to EL, a słownik krajów XSD zna GR.
            $prefix = (string) $text('eu_vat_prefix');
            return [$prefix === 'EL' ? 'GR' : $prefix, $text('eu_vat_number')];
        }
        if ($text('foreign_tax_id') !== null) {
            return [$text('id_country'), $text('foreign_tax_id')];
        }
        return [null, null];
    }

    private function rate(string $id): FxRate
    {
        $row = $this->db->fetchAssociative('SELECT id,currency,table_no,effective_date,mid FROM fx_rate WHERE id=?', [$id]);
        if ($row === false) {
            throw new \LogicException('Brak kursu faktury.');
        }
        return new FxRate(self::str($row['id']), self::str($row['currency']), self::str($row['table_no']), self::str($row['effective_date']), self::str($row['mid']));
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : throw new \LogicException('Niepoprawna wartość wiersza.');
    }
}
