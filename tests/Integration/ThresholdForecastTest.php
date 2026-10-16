<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Fixtures\FictionalIdentifiers;
use App\Ledger\RevenueDocument;
use App\Tax\ThresholdForecast;
use App\Tax\ZusPayments;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\ObligationTestTools;
use App\Time\BusinessDate;
use Symfony\Component\Clock\MockClock;

/**
 * Prognoza progów i rezerwa podatkowa (D-76): run-rate z zakończonych miesięcy wobec limitów w euro z domyślnego
 * `ryczalt.eligibility` (migracja) i progów zdrowotnej 2026 z docs/tax-rules.md. Przychody, składki i kursy EUR są fikcyjne.
 */
final class ThresholdForecastTest extends DatabaseTestCase
{
    public function testQuarterlyLimitCrossingIsForecastAndReserveAddsOpenPeriodAndHealthSurcharge(): void
    {
        $clock = new MockClock('2026-10-16T08:00:00Z');
        $this->seed($clock);
        for ($month = 1; $month <= 9; $month++) {
            $this->revenue($clock, sprintf('FIKCYJNA/%d/2026', $month), sprintf('2026-%02d-28', $month), '80000.00');
            $this->zus($clock, sprintf('2026-%02d', $month), '1600.00', '498.35', sprintf('2026-%02d-10', $month + 1));
        }
        $this->revenue($clock, 'FIKCYJNA/10/2026', '2026-10-10', '10000.00');
        $forecast = $this->forecast($clock, ['EUR' => ['2026-09-30' => '4.3000','2026-10-01' => '4.2500']])->forecast(2026);
        self::assertNotNull($forecast);
        // 9 miesięcy po 80 000,00 zł: średnia 80 000,00, prognoza 960 000,00; do 16.10 przychód 730 000,00.
        self::assertSame([9, '2026-09-30', '720000.00', '730000.00', '80000.00', '960000.00'], [$forecast['basis_months'], $forecast['basis_end'], $forecast['revenue_basis'], $forecast['revenue_ytd'], $forecast['run_rate_monthly'], $forecast['projected_revenue']]);
        $eur = $forecast['eur'];
        self::assertNotNull($eur);
        self::assertSame(['4.250000', '2026-10-01', false], [$eur['rate'], $eur['rate_date'], $eur['rate_estimated']]);
        // 200 000 EUR × 4,25 = 850 000,00 zł: listopad daje 880 000,00 narastająco; 250 000 i 2 mln EUR bez przekroczenia.
        self::assertSame([
            ['key' => 'eligibility','limit_eur' => '2000000.00','limit_pln' => '8500000.00','crossed' => false,'projected_cross' => false,'cross_month' => null],
            ['key' => 'eligibility_watch','limit_eur' => '250000.00','limit_pln' => '1062500.00','crossed' => false,'projected_cross' => false,'cross_month' => null],
            ['key' => 'quarterly','limit_eur' => '200000.00','limit_pln' => '850000.00','crossed' => false,'projected_cross' => true,'cross_month' => '2026-11'],
        ], $eur['limits']);
        $health = $forecast['health'];
        self::assertNotNull($health);
        // Po składkach społecznych 730 000 − 9 × 1 600 = 715 600,00 zł: próg 3 teraz i w prognozie; zapłacone 9 × 498,35
        // wobec 9 × 1 495,04 przy progu 3 daje 8 970,21 zł dopłaty w rozliczeniu rocznym.
        self::assertSame(['715600.00', 3, 3, null, 9, '4485.15', '8970.21'], [$health['revenue_after_social_ytd'], $health['bracket_now'], $health['bracket_projected'], $health['next_limit_pln'], $health['months_paid'], $health['health_paid'], $health['surcharge']]);
        // Październik trwa: 10 000,00 zł (12%) minus odliczenia z 10.10 (1 600,00 + 249,18) → podstawa 8 151 zł, ryczałt 978 zł.
        self::assertSame(['period_start' => '2026-10-01','ryczalt_open_period' => '978.00','health_surcharge' => '8970.21','total' => '9948.21'], $forecast['reserve']);
    }

    public function testHealthBracketCrossingWithEstimatedRateAndPastYear(): void
    {
        $clock = new MockClock('2026-01-20T08:00:00Z');
        $this->seed($clock);
        self::assertNull($this->forecast($clock)->forecast(2026));
        $clock->modify('2026-05-05T08:00:00Z');
        for ($month = 1; $month <= 4; $month++) {
            $this->revenue($clock, sprintf('FIKCYJNA/%d/2026', $month), sprintf('2026-%02d-20', $month), '12000.00');
        }
        for ($month = 1; $month <= 3; $month++) {
            $this->zus($clock, sprintf('2026-%02d', $month), '1600.00', '498.35', sprintf('2026-%02d-10', $month + 1));
        }
        // Przed 1.10.2026 kurs szacunkowy z ostatniej tabeli przed 5.05.2026 (poniedziałek 4.05).
        $forecast = $this->forecast($clock, ['EUR' => ['2026-05-04' => '4.2000']])->forecast(2026);
        self::assertNotNull($forecast);
        self::assertSame([4, '12000.00', '144000.00'], [$forecast['basis_months'], $forecast['run_rate_monthly'], $forecast['projected_revenue']]);
        self::assertSame(['4.200000', '2026-05-04', '2026-10-01', true], [$forecast['eur']['rate'] ?? null, $forecast['eur']['rate_date'] ?? null, $forecast['eur']['rate_day'] ?? null, $forecast['eur']['rate_estimated'] ?? null]);
        $health = $forecast['health'];
        self::assertNotNull($health);
        // Netto 43 200,00 zł po 4 miesiącach, średnio 10 800,00: 60 000 zł przekroczone w czerwcu, rok 129 600,00 (próg 2).
        // Dopłata za 3 zapłacone miesiące: 3 × 830,58 − 3 × 498,35 = 996,69 zł.
        self::assertSame(['43200.00', '129600.00', 1, 2, '60000.00', '2026-06', 3, '996.69'], [$health['revenue_after_social_ytd'], $health['projected_revenue_after_social'], $health['bracket_now'], $health['bracket_projected'], $health['next_limit_pln'], $health['next_cross_month'], $health['months_paid'], $health['surcharge']]);
        $clock->modify('2027-02-10T08:00:00Z');
        // Rok zamknięty: podstawa to 12 miesięcy, prognoza równa przychodowi, bez trwającego okresu zaliczki.
        $past = $this->forecast($clock, ['EUR' => ['2026-10-01' => '4.2500']])->forecast(2026);
        self::assertNotNull($past);
        self::assertSame([12, '48000.00', '48000.00', '4000.00', null, null], [$past['basis_months'], $past['revenue_ytd'], $past['projected_revenue'], $past['run_rate_monthly'], $past['reserve']['period_start'], $past['reserve']['ryczalt_open_period']]);
        $this->expectException(\InvalidArgumentException::class);
        $this->forecast($clock)->forecast(2028);
    }

    /** @param array<string,array<string,string>> $rates */
    private function forecast(MockClock $clock, array $rates = []): ThresholdForecast
    {
        return new ThresholdForecast(ObligationTestTools::tax($this->db(), $clock, $rates), ObligationTestTools::settings($this->db(), $clock), new BusinessDate($clock), $this->db(), ConnectorTestTools::configuration(false));
    }

    private function seed(MockClock $clock): void
    {
        $settings = ObligationTestTools::settings($this->db(), $clock);
        $settings->set('company.tax_micro_account', FictionalIdentifiers::account(1), '2026-01-01', null, 'fictional');
        $settings->set('company.zus_account', FictionalIdentifiers::account(2), '2026-01-01', null, 'fictional');
        $settings->set('zus.parameters', ObligationTestTools::zusParameters('1600.00', '100.00', 1), '2026-01-01', null, 'fictional');
    }

    private function revenue(MockClock $clock, string $number, string $date, string $amount): void
    {
        F1TestTools::writer($this->db(), $clock)->write(new RevenueDocument('manual', null, $number, null, null, null, $date, 'PLN', null, ['12.00' => $amount]), F1TestTools::owner($this->db(), $clock));
    }

    private function zus(MockClock $clock, string $month, string $social, string $health, string $paidOn): void
    {
        (new ZusPayments($this->db(), $clock, F1TestTools::audit($this->db(), $clock), ConnectorTestTools::configuration(false), F1TestTools::periods($this->db(), $clock)))->record(F1TestTools::owner($this->db(), $clock), $month, $social, $health, '0.00', $paidOn);
    }
}
