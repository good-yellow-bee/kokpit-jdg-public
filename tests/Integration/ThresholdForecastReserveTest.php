<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\RevenueDocument;
use App\Tax\ThresholdForecast;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\ObligationTestTools;
use App\Time\BusinessDate;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;

/**
 * Rezerwa na trwający okres zaliczki (miesiąc albo kwartał) i miesiąc przekroczenia limitu w euro (D-76).
 * Rok 2027 ma tylko fikcyjne ustawienia z testu; przychody i kursy EUR są fikcyjne.
 */
final class ThresholdForecastReserveTest extends DatabaseTestCase
{
    /** @return iterable<string,array{int,string}> miesiąc dnia dzisiejszego → początek kwartału */
    public static function quarterStarts(): iterable
    {
        foreach ([2 => '01', 3 => '01', 4 => '04', 5 => '04', 6 => '04', 7 => '07', 8 => '07', 9 => '07', 10 => '10', 11 => '10', 12 => '10'] as $month => $start) {
            yield sprintf('2027-%02d', $month) => [$month, '2027-' . $start . '-01'];
        }
    }

    #[DataProvider('quarterStarts')]
    public function testQuarterlyReserveStartsAtQuarter(int $month, string $start): void
    {
        $clock = new MockClock(sprintf('2027-%02d-16T08:00:00Z', $month));
        ObligationTestTools::settings($this->db(), $clock)->set('ryczalt.advance_frequency', 'quarterly', '2027-01-01', null, 'fictional');
        $forecast = $this->forecast($clock)->forecast(2027);
        self::assertNotNull($forecast);
        self::assertSame(2027, $forecast['year']);
        self::assertSame($start, $forecast['reserve']['period_start']);
    }

    public function testQuarterlyReserveCountsWholeQuarterSoFar(): void
    {
        $clock = new MockClock('2027-11-16T08:00:00Z');
        ObligationTestTools::settings($this->db(), $clock)->set('ryczalt.advance_frequency', 'quarterly', '2027-01-01', null, 'fictional');
        $this->revenue($clock, 'FIKCYJNA/10/2027', '2027-10-10', '10000.00');
        $this->revenue($clock, 'FIKCYJNA/11/2027', '2027-11-10', '10000.00');
        $forecast = $this->forecast($clock)->forecast(2027);
        self::assertNotNull($forecast);
        // Październik i listopad: 20 000,00 zł × 12% bez odliczeń.
        self::assertSame(['period_start' => '2027-10-01','ryczalt_open_period' => '2400.00','health_surcharge' => '0.00','total' => '2400.00'], $forecast['reserve']);
    }

    public function testMonthlyReserveStartsAtCurrentMonth(): void
    {
        $clock = new MockClock('2027-11-16T08:00:00Z');
        ObligationTestTools::settings($this->db(), $clock)->set('ryczalt.advance_frequency', 'monthly', '2027-01-01', null, 'fictional');
        $this->revenue($clock, 'FIKCYJNA/10/2027', '2027-10-10', '10000.00');
        $this->revenue($clock, 'FIKCYJNA/11/2027', '2027-11-10', '5000.00');
        $forecast = $this->forecast($clock)->forecast(2027);
        self::assertNotNull($forecast);
        self::assertSame(['2027-11-01', '600.00'], [$forecast['reserve']['period_start'], $forecast['reserve']['ryczalt_open_period']]);
    }

    public function testLimitCrossedInCurrentMonthWithoutBasisCrossing(): void
    {
        $clock = new MockClock('2027-10-16T08:00:00Z');
        // Do września bez przychodu, więc prognoza z podstawy nie przekracza; 900 000 zł z października już tak.
        $this->revenue($clock, 'FIKCYJNA/10/2027', '2027-10-10', '900000.00');
        $quarterly = $this->quarterlyLimit($clock);
        self::assertSame(['limit_pln' => '850000.00','crossed' => true,'projected_cross' => true,'cross_month' => '2027-10'], $quarterly);
    }

    public function testLimitCrossedInEarlierMonthKeepsThatMonth(): void
    {
        $clock = new MockClock('2027-10-16T08:00:00Z');
        $this->revenue($clock, 'FIKCYJNA/3/2027', '2027-03-10', '900000.00');
        self::assertSame(['limit_pln' => '850000.00','crossed' => true,'projected_cross' => true,'cross_month' => '2027-03'], $this->quarterlyLimit($clock));
    }

    /** @return array<string,mixed> limit kwartalny bez klucza i kwoty w euro */
    private function quarterlyLimit(MockClock $clock): array
    {
        $forecast = $this->forecast($clock, ['EUR' => ['2027-10-01' => '4.2500']])->forecast(2027);
        self::assertNotNull($forecast);
        $eur = $forecast['eur'];
        self::assertNotNull($eur);
        self::assertSame(['001/A/NBP/2027', false], [$eur['rate_table'], $eur['rate_estimated']]);
        foreach ($eur['limits'] as $limit) {
            if ($limit['key'] === 'quarterly') {
                return ['limit_pln' => $limit['limit_pln'],'crossed' => $limit['crossed'],'projected_cross' => $limit['projected_cross'],'cross_month' => $limit['cross_month']];
            }
        }
        self::fail('Brak limitu kwartalnego.');
    }

    /** @param array<string,array<string,string>> $rates */
    private function forecast(MockClock $clock, array $rates = []): ThresholdForecast
    {
        return new ThresholdForecast(ObligationTestTools::tax($this->db(), $clock, $rates), ObligationTestTools::settings($this->db(), $clock), new BusinessDate($clock), $this->db(), ConnectorTestTools::configuration(false));
    }

    private function revenue(MockClock $clock, string $number, string $date, string $amount): void
    {
        F1TestTools::writer($this->db(), $clock)->write(new RevenueDocument('manual', null, $number, null, null, null, $date, 'PLN', null, ['12.00' => $amount]), F1TestTools::owner($this->db(), $clock));
    }
}
