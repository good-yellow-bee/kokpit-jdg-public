<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Fixtures\FictionalIdentifiers;
use App\Fx\FxRateUnavailable;
use App\Ledger\RevenueDocument;
use App\Setting\SettingKeys;
use App\Tax\ZusPayments;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\LedgerTestTools;
use App\Tests\Support\ObligationTestTools;
use Brick\Math\BigDecimal;
use Symfony\Component\Clock\MockClock;

/**
 * Kwoty F2.3 z ewidencji i `zus_payment` (kryteria `ryczalt-advance-obligation`, `health-reconciliation`, `pit28-summary`,
 * `ryczalt-eligibility-metric`). Przychody, składki i kurs EUR są fikcyjne; progi zdrowotnej 2026 z docs/tax-rules.md.
 */
final class TaxCalculatorTest extends DatabaseTestCase
{
    public function testRyczaltAdvanceObligationForNovemberUsesLedgerAndZusPayments(): void
    {
        $clock = new MockClock('2026-12-05T08:00:00Z');
        $this->seed($clock);
        ObligationTestTools::engine($this->db(), $clock)->recompute();
        $row = $this->db()->fetchAssociative("SELECT dedupe_key, rule_key, period_start, due_date, amount_due, payment_title FROM obligation WHERE dedupe_key='ryczalt_advance:2026-11-01'");
        // Przykład z docs/tax-rules.md: 20 000,00 zł (12%) i 5 000,00 zł (8,5%), odliczenia 1 600,00 + 50% z 830,58.
        self::assertSame(['dedupe_key' => 'ryczalt_advance:2026-11-01','rule_key' => 'ryczalt_advance.monthly','period_start' => '2026-11-01','due_date' => '2026-12-21','amount_due' => '2597.00','payment_title' => 'PPE 26M11'], $row);
        // Grudzień trwa: kwoty jeszcze nie ma.
        self::assertNull($this->db()->fetchOne("SELECT amount_due FROM obligation WHERE dedupe_key='ryczalt_advance:2026-12-01'"));
        EvidenceArtefacts::write('db/ryczalt-advance-obligation-obligation.csv', EvidenceArtefacts::csv([$row]));
    }

    public function testRyczaltAdvanceObligationCarriesExcessDeductionsWithinYear(): void
    {
        $clock = new MockClock('2026-11-05T08:00:00Z');
        $this->seed($clock, false);
        // Wrzesień: 1 000,00 zł przychodu i składki za sierpień zapłacone we wrześniu, nadwyżka 1 015,29 zł przechodzi.
        $this->revenue($clock, 'FIKCYJNA/9/2026', '2026-09-30', ['12.00' => '1000.00']);
        $this->zus($clock, '2026-08', '1600.00', '830.58', '2026-09-10');
        $this->revenue($clock, 'FIKCYJNA/10/2026', '2026-10-31', ['12.00' => '10000.00']);
        $advance = ObligationTestTools::tax($this->db(), $clock)->advance('2026-10-01');
        self::assertSame(['1015.29', '0.00', '1078'], [$advance['carried_in'], $advance['paid_deductions'], $advance['result']->advance]);
        ObligationTestTools::engine($this->db(), $clock)->recompute();
        self::assertSame('1078.00', $this->db()->fetchOne("SELECT amount_due FROM obligation WHERE dedupe_key='ryczalt_advance:2026-10-01'"));
    }

    public function testHealthReconciliationAmountDueAfterYearEnd(): void
    {
        $clock = new MockClock('2026-12-05T08:00:00Z');
        $this->seed($clock);
        $clock->modify('2027-01-15T08:00:00Z');
        ObligationTestTools::engine($this->db(), $clock)->recompute();
        $row = $this->db()->fetchAssociative("SELECT dedupe_key, period_start, due_date, amount_due FROM obligation WHERE type='health_reconciliation' AND period_start='2026-01-01'");
        // Przychód 25 000,00 − społeczne 1 600,00 = 23 400,00 zł: próg 1 (498,35 zł) × 12 miesięcy = 5 980,20; zapłacono 830,58.
        self::assertIsArray($row);
        self::assertSame('5149.62', $row['amount_due']);
        $health = ObligationTestTools::tax($this->db(), $clock)->healthReconciliation(2026);
        self::assertNotNull($health);
        self::assertSame(['23400.00', 1, 12, '5980.20', '830.58', '5149.62', false], [$health['revenue_after_social'], $health['bracket'], $health['months'], $health['due'], $health['paid'], $health['difference'], $health['refund_task']]);
        EvidenceArtefacts::write('db/health-reconciliation-obligation.csv', EvidenceArtefacts::csv([$row]));
    }

    public function testHealthReconciliationOverpaymentGivesZeroAndRefundTask(): void
    {
        $clock = new MockClock('2026-12-05T08:00:00Z');
        $this->seed($clock);
        foreach (['01', '02', '03', '04', '05', '06', '07', '08', '09', '11', '12'] as $month) {
            $this->zus($clock, '2026-' . $month, '0.00', '830.58', '2026-12-01');
        }
        $clock->modify('2027-01-15T08:00:00Z');
        $health = ObligationTestTools::tax($this->db(), $clock)->healthReconciliation(2026);
        self::assertNotNull($health);
        // 12 × 830,58 = 9 966,96 zapłacone wobec 5 980,20 należnych: nadpłata, wniosek RZS-R.
        self::assertSame(['9966.96', '-3986.76', '0.00', true], [$health['paid'], $health['difference'], $health['amount_due'], $health['refund_task']]);
        ObligationTestTools::engine($this->db(), $clock)->recompute();
        self::assertSame('0.00', $this->db()->fetchOne("SELECT amount_due FROM obligation WHERE type='health_reconciliation' AND period_start='2026-01-01'"));
    }

    public function testHealthReconciliationCountsMonthsFromFirstParametersOfYear(): void
    {
        $clock = new MockClock('2027-01-15T08:00:00Z');
        self::assertNull(ObligationTestTools::tax($this->db(), $clock)->healthReconciliation(2026));
        // Parametry od kwietnia (np. start działalności): 9 miesięcy podlegania × 498,35 zł = 4 485,15 zł.
        ObligationTestTools::settings($this->db(), $clock)->set('zus.parameters', ObligationTestTools::zusParameters('1600.00', '100.00', 1), '2026-04-01', null, 'fictional');
        $tax = ObligationTestTools::tax($this->db(), $clock);
        $health = $tax->healthReconciliation(2026);
        self::assertNotNull($health);
        self::assertSame([9, '4485.15', '4485.15'], [$health['months'], $health['due'], $health['amount_due']]);
        self::assertNull($tax->healthReconciliation(2027));
    }

    public function testPit28SummaryMatchesLedgerTotalsPerRate(): void
    {
        $clock = new MockClock('2026-12-05T08:00:00Z');
        $this->seed($clock);
        $this->revenue($clock, 'FIKCYJNA/3/2026', '2026-03-15', ['17.00' => '1234.56']);
        $summary = ObligationTestTools::tax($this->db(), $clock)->pit28(2026);
        $ledger = [];
        foreach (LedgerTestTools::rows($this->db()) as $row) {
            $rate = is_string($row['k_9']) ? $row['k_9'] : '';
            $ledger[$rate] = (string) BigDecimal::of($ledger[$rate] ?? '0')->plus(is_string($row['k_8']) ? $row['k_8'] : '0')->toScale(2);
        }
        ksort($ledger);
        self::assertSame($ledger, $summary['revenue']);
        $actual = '';
        foreach ($summary['revenue'] as $rate => $amount) {
            $actual .= sprintf("revenue;%s;%s\n", $rate, $amount);
        }
        $actual .= sprintf("revenue_total;%s\nsocial_paid;%s\nhealth_paid;%s\nhealth_deductible;%s\n", $summary['revenue_total'], $summary['social_paid'], $summary['health_paid'], $summary['health_deductible']);
        $expected = "revenue;8.50;5000.00\nrevenue;12.00;20000.00\nrevenue;17.00;1234.56\nrevenue_total;26234.56\nsocial_paid;1600.00\nhealth_paid;830.58\nhealth_deductible;415.29\n";
        EvidenceArtefacts::write('golden/pit28-summary.diff', $expected === $actual ? '' : "--- oczekiwane\n+++ wynik\n-" . $expected . '+' . $actual);
        self::assertSame($expected, $actual);
    }

    public function testRyczaltEligibilityMetricUsesEurRateFromFirstWorkingDayOfOctober(): void
    {
        $clock = new MockClock('2026-12-05T08:00:00Z');
        $this->seed($clock);
        ObligationTestTools::settings($this->db(), $clock)->set(SettingKeys::RYCZALT_ELIGIBILITY, ['limit_eur' => '2000000.00','watch_limit_eur' => '250000.00'], '2026-01-01', null, 'fictional');
        // 1.10.2025 to środa: kurs fikcyjny 4,2500 z tabeli tego dnia; 25 000,00 zł / 4,25 = 5 882,35 EUR.
        $metric = ObligationTestTools::tax($this->db(), $clock, ['EUR' => ['2025-09-30' => '4.3000','2025-10-01' => '4.2500']])->eligibility(2026);
        self::assertSame(['25000.00', '4.250000', '2025-10-01', '5882.35', '2000000.00', '250000.00', '2.35'], [$metric['revenue_pln'], $metric['rate'], $metric['rate_date'], $metric['revenue_eur'], $metric['limit_eur'], $metric['watch_limit_eur'], $metric['watch_used_pct']]);
    }

    public function testRyczaltEligibilityMetricRefusesRateFromAnotherDay(): void
    {
        $clock = new MockClock('2026-12-05T08:00:00Z');
        $this->seed($clock);
        $this->expectException(FxRateUnavailable::class);
        ObligationTestTools::tax($this->db(), $clock, ['EUR' => ['2025-09-30' => '4.3000']])->eligibility(2026);
    }

    public function testZusPaymentUpsertsMonthLinksObligationAndAudits(): void
    {
        $clock = new MockClock('2026-12-05T08:00:00Z');
        ObligationTestTools::seed($this->db(), $clock);
        ObligationTestTools::engine($this->db(), $clock)->recompute();
        $first = $this->zus($clock, '2026-11', '1600.00', '830.58', '2026-12-05');
        $second = $this->zus($clock, '2026-11', '1600.00', '498.35', '2026-12-06');
        self::assertSame($first, $second);
        $row = $this->db()->fetchAssociative("SELECT p.health_pln, p.paid_on, o.dedupe_key FROM zus_payment p LEFT JOIN obligation o ON o.id=p.obligation_id WHERE p.id=?", [$first]);
        self::assertSame(['health_pln' => '498.35','paid_on' => '2026-12-06','dedupe_key' => 'zus:2026-11-01'], $row);
        self::assertSame(2, F1TestTools::count($this->db(), "SELECT count(*) FROM audit_log WHERE action='zus.payment_recorded' AND target_id=?", [$first]));
        foreach ([['2026-13', '1.00'], ['2026-11', '-1.00'], ['2026-11', '1.5']] as [$month, $amount]) {
            try {
                $this->zus($clock, $month, $amount, '0.00', '2026-12-06');
                self::fail('Składki przyjęte.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** Rachunki, `zus.parameters` 2026 (próg z przychodu), przychód 11.2026 i składki za 10.2026 zapłacone w listopadzie. */
    private function seed(MockClock $clock, bool $november = true): void
    {
        $settings = ObligationTestTools::settings($this->db(), $clock);
        $settings->set('company.tax_micro_account', FictionalIdentifiers::account(1), '2026-01-01', null, 'fictional');
        $settings->set('company.zus_account', FictionalIdentifiers::account(2), '2026-01-01', null, 'fictional');
        $settings->set('zus.parameters', ObligationTestTools::zusParameters('1600.00', '100.00', 1), '2026-01-01', null, 'fictional');
        $settings->set('jpk_st.enabled', false, '2026-01-01', null, 'fictional');
        if ($november) {
            $this->revenue($clock, 'FIKCYJNA/11/2026', '2026-11-30', ['12.00' => '20000.00','8.50' => '5000.00']);
            $this->zus($clock, '2026-10', '1600.00', '830.58', '2026-11-10');
        }
    }

    /** @param array<string,string> $byRate */
    private function revenue(MockClock $clock, string $number, string $date, array $byRate): void
    {
        F1TestTools::writer($this->db(), $clock)->write(new RevenueDocument('manual', null, $number, null, null, null, $date, 'PLN', null, $byRate), F1TestTools::owner($this->db(), $clock));
    }

    private function zus(MockClock $clock, string $month, string $social, string $health, string $paidOn): string
    {
        return (new ZusPayments($this->db(), $clock, F1TestTools::audit($this->db(), $clock), ConnectorTestTools::configuration(false)))->record(F1TestTools::owner($this->db(), $clock), $month, $social, $health, '0.00', $paidOn);
    }
}
