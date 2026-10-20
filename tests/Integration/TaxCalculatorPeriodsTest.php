<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Fixtures\FictionalIdentifiers;
use App\Fx\FxRateUnavailable;
use App\Ledger\RevenueDocument;
use App\Tax\ZusPayments;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\ObligationTestTools;
use Symfony\Component\Clock\MockClock;

/**
 * Okresy, lata i kurs progów w `TaxCalculator`: zaliczka kwartalna, próg zdrowotnej tylko z przychodu roku, pola
 * zestawień i kurs EUR w sam dzień progu. Przychody, składki i kursy są fikcyjne.
 */
final class TaxCalculatorPeriodsTest extends DatabaseTestCase
{
    public function testQuarterlyAdvanceCoversWholeQuarter(): void
    {
        $clock = new MockClock('2026-07-10T08:00:00Z');
        $this->seed($clock);
        $this->revenue($clock, 'FIKCYJNA/4/2026', '2026-04-15', ['12.00' => '1000.00']);
        $this->revenue($clock, 'FIKCYJNA/5/2026', '2026-05-20', ['12.00' => '2000.00']);
        $tax = ObligationTestTools::tax($this->db(), $clock);
        $quarter = $tax->advance('2026-04-01', 'quarterly');
        self::assertSame(['2026-04-01', '2026-06-30', ['12.00' => '3000.00'], '360'], [$quarter['period_start'], $quarter['period_end'], $quarter['revenue'], $quarter['result']->advance]);
        $month = $tax->advance('2026-05-01');
        self::assertSame(['2026-05-01', '2026-05-31', ['12.00' => '2000.00']], [$month['period_start'], $month['period_end'], $month['revenue']]);
    }

    public function testZusAmountBracketIgnoresRevenueOfEarlierYears(): void
    {
        $clock = new MockClock('2026-12-05T08:00:00Z');
        $this->seed($clock);
        $this->revenue($clock, 'FIKCYJNA/6/2025', '2025-06-15', ['12.00' => '400000.00']);
        $this->revenue($clock, 'FIKCYJNA/11/2026', '2026-11-20', ['12.00' => '10000.00']);
        // 1 600,00 + 100,00 + próg 1 (498,35): przychód 2025 nie liczy się do progu 2026.
        self::assertSame('2198.35', ObligationTestTools::tax($this->db(), $clock)->zusAmount('2026-11-01'));
    }

    public function testSummariesCarryYearAndStringAmounts(): void
    {
        $clock = new MockClock('2027-01-15T08:00:00Z');
        $this->seed($clock);
        $this->revenue($clock, 'FIKCYJNA/11/2026', '2026-11-30', ['12.00' => '20000.00']);
        $this->zus($clock, '2026-10', '1600.00', '830.59', '2026-11-10');
        $tax = ObligationTestTools::tax($this->db(), $clock);
        $pit = $tax->pit28(2026);
        self::assertSame([2026, '20000.00', '1600.00', '830.59', '415.30'], [$pit['year'], $pit['revenue_total'], $pit['social_paid'], $pit['health_paid'], $pit['health_deductible']]);
        $health = $tax->healthReconciliation(2026);
        self::assertNotNull($health);
        self::assertSame(2026, $health['year']);
    }

    public function testEligibilityUsesDefaultLimitsAndRateTable(): void
    {
        $clock = new MockClock('2026-12-05T08:00:00Z');
        $this->seed($clock);
        $this->revenue($clock, 'FIKCYJNA/11/2026', '2026-11-30', ['12.00' => '42500.00']);
        $metric = ObligationTestTools::tax($this->db(), $clock, ['EUR' => ['2026-10-01' => '4.2500']])->eligibility(2026);
        // Limity domyślne z migracji: 2 mln, obserwacja 250 tys., kwartalny 200 tys. EUR.
        self::assertSame([2026, '001/A/NBP/2026', '10000.00', '2000000.00', '250000.00', '200000.00', '4.00'], [$metric['year'], $metric['rate_table'], $metric['revenue_eur'], $metric['limit_eur'], $metric['watch_limit_eur'], $metric['quarterly_limit_eur'], $metric['watch_used_pct']]);
    }

    public function testThresholdRateOnTheDayUsesPublishedTable(): void
    {
        // 1.10.2026, 14:00 w Warszawie: tabela z tego dnia już jest, więc kurs nie jest szacunkiem.
        $clock = new MockClock('2026-10-01T12:00:00Z');
        $threshold = ObligationTestTools::tax($this->db(), $clock, ['EUR' => ['2026-09-30' => '4.3000','2026-10-01' => '4.2500']])->eurThresholdRate(2026);
        self::assertSame(['2026-10-01', '4.250000', '2026-10-01', false], [$threshold['day'], $threshold['rate']->mid, $threshold['rate']->effectiveDate, $threshold['estimated']]);
    }

    public function testThresholdRateAfterTheDayRefusesLaterTables(): void
    {
        $clock = new MockClock('2026-12-05T08:00:00Z');
        $tax = ObligationTestTools::tax($this->db(), $clock, ['EUR' => ['2026-09-30' => '4.3000','2026-12-04' => '4.2000']]);
        $this->expectException(FxRateUnavailable::class);
        $tax->eurThresholdRate(2026);
    }

    private function seed(MockClock $clock): void
    {
        $settings = ObligationTestTools::settings($this->db(), $clock);
        $settings->set('company.tax_micro_account', FictionalIdentifiers::account(1), '2026-01-01', null, 'fictional');
        $settings->set('company.zus_account', FictionalIdentifiers::account(2), '2026-01-01', null, 'fictional');
        $settings->set('zus.parameters', ObligationTestTools::zusParameters('1600.00', '100.00', 1), '2026-01-01', null, 'fictional');
    }

    /** @param array<string,string> $byRate */
    private function revenue(MockClock $clock, string $number, string $date, array $byRate): void
    {
        F1TestTools::writer($this->db(), $clock)->write(new RevenueDocument('manual', null, $number, null, null, null, $date, 'PLN', null, $byRate), F1TestTools::owner($this->db(), $clock));
    }

    private function zus(MockClock $clock, string $month, string $social, string $health, string $paidOn): void
    {
        (new ZusPayments($this->db(), $clock, F1TestTools::audit($this->db(), $clock), ConnectorTestTools::configuration(false), F1TestTools::periods($this->db(), $clock)))->record(F1TestTools::owner($this->db(), $clock), $month, $social, $health, '0.00', $paidOn);
    }
}
