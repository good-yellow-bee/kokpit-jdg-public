<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\YearOverYearReport;
use Symfony\Component\Uid\UuidV7;

/** Raport rok do roku (D-87): wiersz roku z grudniem, okres porównywalny i średni kurs ze wszystkich tabel. Dane i kursy fikcyjne. */
final class YearOverYearReportTotalsTest extends DatabaseTestCase
{
    public function testYearTotalIncludesDecemberAndAveragesAllTables(): void
    {
        $this->revenue(2031, 1, '2031-10-05', '10.00');
        $this->revenue(2031, 2, '2031-12-05', '100.00');
        $this->revenue(2030, 1, '2030-12-05', '40.00');
        $this->rate('EUR', '2031-01-02', '4.000000');
        $this->rate('EUR', '2031-12-01', '5.000000');
        $this->rate('EUR', '2030-01-02', '4.000000');
        $this->rate('EUR', '2030-12-01', '4.400000');

        $report = (new YearOverYearReport($this->db()))->build('test', 2031, 'EUR', '2031-06-15');
        $total = $report['total'];
        self::assertSame(['110.00', '40.00', '70.00'], [$total['current']['revenue_pln'], $total['previous']['revenue_pln'], $total['diff']['revenue_pln']]);
        self::assertSame(['4.5000', '2', '4.2000', '2', '0.3000'], [$total['current']['fx_mid'], $total['current']['fx_tables'], $total['previous']['fx_mid'], $total['previous']['fx_tables'], $total['diff']['fx_mid']]);
        self::assertSame(['5.0000', '4.4000'], [$report['months'][11]['current']['fx_mid'], $report['months'][11]['previous']['fx_mid']]);

        $comparable = $report['comparable'];
        self::assertNotNull($comparable);
        self::assertSame('od-01-do-06', $comparable['month']);
        self::assertSame([null, null, null], [$comparable['current']['revenue_pln'], $comparable['previous']['revenue_pln'], $comparable['diff']['revenue_pln']]);
        self::assertSame(['4.0000', '1', '4.0000', '1'], [$comparable['current']['fx_mid'], $comparable['current']['fx_tables'], $comparable['previous']['fx_mid'], $comparable['previous']['fx_tables']]);
    }

    public function testYearWithoutTablesHasNoRate(): void
    {
        $report = (new YearOverYearReport($this->db()))->build('test', 2031, 'EUR', '2032-01-10');
        self::assertNull($report['comparable']);
        self::assertSame([null, null, null], [$report['total']['current']['fx_mid'], $report['total']['current']['fx_tables'], $report['total']['diff']['fx_mid']]);
    }

    public function testCurrenciesAreUniqueSortedAndAlwaysIncludeEur(): void
    {
        $report = new YearOverYearReport($this->db());
        $this->rate('USD', '2031-01-02', '3.900000');
        $this->rate('CHF', '2031-01-02', '4.500000');
        $this->rate('USD', '2031-01-03', '3.910000');
        self::assertSame(['CHF', 'EUR', 'USD'], $report->currencies());
        $this->rate('EUR', '2031-01-02', '4.250000');
        self::assertSame(['CHF', 'EUR', 'USD'], $report->currencies());
    }

    private function revenue(int $year, int $lp, string $date, string $amount): void
    {
        $this->db()->insert('revenue_entry', ['id' => (string) UuidV7::generate(), 'environment' => 'test', 'year' => $year, 'lp' => $lp, 'entry_date' => $date, 'revenue_date' => $date, 'document_number' => 'FIKC/RR/' . $lp,
            'amount_pln' => $amount, 'rate_pct' => '12.00', 'source' => 'import', 'kind' => 'regular', 'recorded_at' => '2031-12-31T10:00:00+00:00']);
    }

    private function rate(string $currency, string $date, string $mid): void
    {
        $this->db()->insert('fx_rate', ['id' => (string) UuidV7::generate(), 'source' => 'nbp', 'table_no' => 'FIKC/' . $date, 'currency' => $currency, 'effective_date' => $date, 'mid' => $mid, 'fetched_at' => '2031-12-31T10:00:00+00:00']);
    }
}
