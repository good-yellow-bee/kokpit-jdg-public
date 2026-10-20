<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Calendar\IsoDate;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;

/**
 * Raport rok do roku (D-87): przychód z ewidencji, VAT z przyjętych JPK_V7M, koszty firmowe i średni kurs NBP per miesiąc
 * roku wobec roku poprzedniego. Tylko odczyt zapisanych danych; brak danych to `null`, nie zero.
 * - Przychód: suma `revenue_entry.amount_pln` wszystkich rodzajów wierszy według miesiąca `revenue_date`.
 * - VAT: `totals` najnowszego przyjętego JPK_V7M okresu (`vat_due`, `vat_input`, `p51`); bez przyjętego złożenia brak liczby.
 * - Koszty: suma `invoice.net_pln` faktur kosztowych z `cost_review` = `business` według miesiąca `issue_date`;
 *   faktury bez kwoty w PLN liczy `costs_without_pln`.
 * - Kurs: średnia `fx_rate.mid` tabel NBP w bazie (pobranych przez kokpit), nie wszystkich tabel miesiąca.
 */
final readonly class YearOverYearReport
{
    /** Miary z różnicą rok do roku; różnica tylko przy obu wartościach, a dla sum także przy jednej (brak = 0). */
    public const SUMS = ['revenue_pln','costs_net_pln'];
    public const KNOWN = ['vat_payable','fx_mid'];
    public const METRICS = ['revenue_pln','vat_due','vat_input','vat_payable','costs_net_pln','costs_documents','costs_without_pln','fx_mid','fx_tables'];

    public function __construct(private Connection $db) {}

    /**
     * @return array{year:int,previous:int,currency:string,months:list<array{month:string,current:array<string,?string>,previous:array<string,?string>,diff:array<string,?string>}>,total:array{month:string,current:array<string,?string>,previous:array<string,?string>,diff:array<string,?string>},comparable:?array{month:string,current:array<string,?string>,previous:array<string,?string>,diff:array<string,?string>}}
     */
    public function build(string $environment, int $year, string $currency, string $today): array
    {
        $previous = $year - 1;
        $data = [$previous => $this->year($environment, $previous, $currency), $year => $this->year($environment, $year, $currency)];
        $months = [];
        for ($month = 1; $month <= 12; ++$month) {
            $months[] = self::row(sprintf('%02d', $month), $data[$year][$month], $data[$previous][$month]);
        }
        $total = self::row('rok', self::sum($data[$year], 12) + $this->fx($year, 12, $currency), self::sum($data[$previous], 12) + $this->fx($previous, 12, $currency));
        // Bieżący rok: te same miesiące roku poprzedniego (styczeń do bieżącego miesiąca), bo pełny rok poprzedni zawyża różnicę.
        $comparable = null;
        if (IsoDate::year($today) === $year) {
            $until = IsoDate::month($today);
            $comparable = self::row(sprintf('od-01-do-%02d', $until), self::sum($data[$year], $until) + $this->fx($year, $until, $currency), self::sum($data[$previous], $until) + $this->fx($previous, $until, $currency));
        }
        return ['year' => $year,'previous' => $previous,'currency' => $currency,'months' => $months,'total' => $total,'comparable' => $comparable];
    }

    /** @return list<string> waluty z tabel NBP w bazie, zawsze z EUR */
    public function currencies(): array
    {
        $currencies = array_filter($this->db->fetchFirstColumn("SELECT DISTINCT currency FROM fx_rate WHERE source='nbp'"), 'is_string');
        $currencies = array_values(array_unique([...$currencies, 'EUR']));
        sort($currencies);
        return $currencies;
    }

    /** @return array<int, array<string, ?string>> miesiąc 1–12 → miary */
    private function year(string $environment, int $year, string $currency): array
    {
        $empty = array_fill_keys(self::METRICS, null);
        $months = array_fill(1, 12, $empty);
        $range = [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)];
        foreach ($this->db->fetchAllAssociative('SELECT extract(month FROM revenue_date)::int AS m, sum(amount_pln) AS total FROM revenue_entry WHERE environment=? AND year=? GROUP BY 1', [$environment, $year]) as $row) {
            $months[self::month($row['m'])]['revenue_pln'] = self::money($row['total']);
        }
        // Korekta JPK_V7M zastępuje wcześniejsze złożenie okresu: najwyższy `seq`, jak P_39 w `JpkV7mBuilder`.
        $filings = $this->db->fetchAllAssociative(
            "SELECT DISTINCT ON (period_start) extract(month FROM period_start)::int AS m, totals->>'vat_due' AS vat_due, totals->>'vat_input' AS vat_input, totals->>'p51' AS p51
             FROM filing WHERE environment=? AND type='JPK_V7M' AND status='accepted' AND period_start BETWEEN ? AND ? ORDER BY period_start, seq DESC, accepted_at DESC",
            [$environment, ...$range],
        );
        foreach ($filings as $row) {
            $month = self::month($row['m']);
            $months[$month]['vat_due'] = self::money($row['vat_due']);
            $months[$month]['vat_input'] = self::money($row['vat_input']);
            $months[$month]['vat_payable'] = self::money($row['p51']);
        }
        $costs = $this->db->fetchAllAssociative(
            "SELECT extract(month FROM issue_date)::int AS m, sum(net_pln) AS total, count(*) AS documents, count(*) FILTER (WHERE net_pln IS NULL) AS without_pln
             FROM invoice WHERE environment=? AND direction='cost' AND cost_review='business' AND issue_date BETWEEN ? AND ? GROUP BY 1",
            [$environment, ...$range],
        );
        foreach ($costs as $row) {
            $month = self::month($row['m']);
            $months[$month]['costs_net_pln'] = self::money($row['total']);
            $months[$month]['costs_documents'] = self::count($row['documents']);
            $months[$month]['costs_without_pln'] = self::count($row['without_pln']);
        }
        foreach ($this->db->fetchAllAssociative("SELECT extract(month FROM effective_date)::int AS m, round(avg(mid), 4) AS mid, count(*) AS tables FROM fx_rate WHERE source='nbp' AND currency=? AND effective_date BETWEEN ? AND ? GROUP BY 1", [$currency, ...$range]) as $row) {
            $month = self::month($row['m']);
            $months[$month]['fx_mid'] = self::rate($row['mid']);
            $months[$month]['fx_tables'] = self::count($row['tables']);
        }
        return $months;
    }

    /** @return array{fx_mid:?string,fx_tables:?string} średnia ze wszystkich tabel od stycznia do końca miesiąca `$until`, nie ze średnich miesięcznych */
    private function fx(int $year, int $until, string $currency): array
    {
        $end = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $until)))->format('Y-m-t');
        $row = $this->db->fetchAssociative("SELECT round(avg(mid), 4) AS mid, count(*) AS tables FROM fx_rate WHERE source='nbp' AND currency=? AND effective_date BETWEEN ? AND ?", [$currency, sprintf('%04d-01-01', $year), $end]) ?: [];
        $tables = self::count($row['tables'] ?? 0);
        return $tables === '0' ? ['fx_mid' => null,'fx_tables' => null] : ['fx_mid' => self::rate($row['mid'] ?? null),'fx_tables' => $tables];
    }

    /**
     * @param array<int, array<string, ?string>> $months
     * @return array<string, ?string> sumy miar kwotowych i liczb od stycznia do miesiąca `$until`, bez kursu
     */
    private static function sum(array $months, int $until): array
    {
        $out = array_fill_keys(array_diff(self::METRICS, ['fx_mid','fx_tables']), null);
        foreach (array_slice($months, 0, $until, true) as $values) {
            foreach (array_keys($out) as $metric) {
                if ($values[$metric] !== null) {
                    $out[$metric] = (string) BigDecimal::of($out[$metric] ?? '0')->plus($values[$metric]);
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string, ?string> $current
     * @param array<string, ?string> $previous
     * @return array{month:string,current:array<string,?string>,previous:array<string,?string>,diff:array<string,?string>}
     */
    private static function row(string $month, array $current, array $previous): array
    {
        $diff = [];
        foreach (self::SUMS as $metric) {
            $diff[$metric] = $current[$metric] === null && $previous[$metric] === null ? null : (string) BigDecimal::of($current[$metric] ?? '0')->minus($previous[$metric] ?? '0');
        }
        foreach (self::KNOWN as $metric) {
            $diff[$metric] = $current[$metric] === null || $previous[$metric] === null ? null : (string) BigDecimal::of($current[$metric])->minus($previous[$metric]);
        }
        return ['month' => $month,'current' => $current,'previous' => $previous,'diff' => $diff];
    }

    private static function month(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw new \LogicException('Niepoprawny miesiąc raportu.');
    }

    private static function money(mixed $value): ?string
    {
        return $value === null ? null : (is_numeric($value) ? (string) BigDecimal::of((string) $value)->toScale(2) : throw new \LogicException('Niepoprawna kwota raportu.'));
    }

    private static function rate(mixed $value): ?string
    {
        return $value === null ? null : (is_numeric($value) ? (string) BigDecimal::of((string) $value)->toScale(4) : throw new \LogicException('Niepoprawny kurs raportu.'));
    }

    private static function count(mixed $value): string
    {
        return is_numeric($value) ? (string) (int) $value : throw new \LogicException('Niepoprawna liczba raportu.');
    }
}
