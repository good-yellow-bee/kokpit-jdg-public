<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/**
 * Fikcyjny rok przychodu 2025 dla PIT-28(27) (D-96): 12% po 15 000,00 zł co miesiąc i 8,5% po 2 400,50 zł w maju
 * i we wrześniu (razem 184 801,00 zł), składki ZUS zapłacone w każdym miesiącu (społeczne 1 600,32 zł, zdrowotna
 * 830,58 zł, pozostałe 120,00 zł poza odliczeniem), zaliczki za styczeń–listopad wpłacone w wysokości z ewidencji,
 * grudzień bez wpłaty. 12 okresów zamkniętych, poświadczenie `auth_data`, przyjęty JPK_EWP z tą samą sumą i obowiązek
 * `pit_28` roku. Tożsamość, adres i dane są fikcyjne. Wywołujący trzyma transakcję.
 */
final class Pit28TestTools
{
    public const YEAR = 2025;
    public const NOW = '2026-03-10T09:00:00Z';
    public const REVENUE_TOTAL = '184801.00';
    /** @var array<string,string> */
    public const IDENTITY = ['nip' => '1111111111','tax_office_code' => '1433','first_name' => 'Fictional','last_name' => 'Podatnik','email' => 'fictional@example.invalid'];
    /** @var array<string,string> */
    public const RESIDENCE = ['province' => 'FIKCYJNE','county' => 'FIKCYJNY','municipality' => 'FIKCYJNA','street' => 'Fikcyjna','building' => '1','unit' => '2','city' => 'Fikcyjne','postal_code' => '00-001'];

    /** @return array{ewp:string,obligation:string} identyfikatory złożenia JPK_EWP i obowiązku `pit_28` */
    public static function seed(Connection $db, MockClock $clock, string $userId, bool $quarterly = false, bool $residence = true, string $nip = self::IDENTITY['nip']): array
    {
        $stamp = $clock->now()->format('Y-m-d\TH:i:s.uP');
        $settings = JpkTestTools::settings($db, $clock);
        $settings->set('company.identity', ['nip' => $nip] + self::IDENTITY, '2025-01-01', null, 'fictional');
        if ($residence) {
            $settings->set('taxpayer.residence_address', self::RESIDENCE, '2025-01-01', null, 'fictional');
        }
        $settings->set('ryczalt.advance_frequency', $quarterly ? 'quarterly' : 'monthly', '2025-01-01', null, 'fictional');
        $lp = 0;
        for ($month = 1; $month <= 12; ++$month) {
            $day = sprintf('%04d-%02d-15', self::YEAR, $month);
            foreach ($month === 5 || $month === 9 ? [['15000.00', '12.00'], ['2400.50', '8.50']] : [['15000.00', '12.00']] as [$amount, $rate]) {
                ++$lp;
                $db->insert('revenue_entry', ['id' => (string) UuidV7::generate($clock->now()),'environment' => 'test','year' => self::YEAR,'lp' => $lp,'entry_date' => $day,'revenue_date' => $day,
                    'document_number' => sprintf('FV/%d/%02d/%d (fictional)', self::YEAR, $month, $lp),'counterparty_country' => 'DE','amount_pln' => $amount,'rate_pct' => $rate,
                    'source' => 'import','kind' => 'regular','recorded_at' => $stamp]);
            }
            $db->insert('zus_payment', ['id' => (string) UuidV7::generate($clock->now()),'environment' => 'test','month' => sprintf('%04d-%02d-01', self::YEAR, $month),'social_pln' => '1600.32',
                'health_pln' => '830.58','other_pln' => '120.00','paid_on' => sprintf('%04d-%02d-20', self::YEAR, $month),'entered_by' => $userId,'created_at' => $stamp,'updated_at' => $stamp]);
            $db->insert('period', ['id' => (string) UuidV7::generate($clock->now()),'environment' => 'test','period_start' => sprintf('%04d-%02d-01', self::YEAR, $month),'status' => 'open',
                'ledger_closed_at' => $stamp,'created_at' => $stamp,'updated_at' => $stamp]);
        }
        $tax = ObligationTestTools::tax($db, $clock);
        $cadence = $quarterly ? 'quarterly' : 'monthly';
        for ($month = 1; $month <= 12; $month += $quarterly ? 3 : 1) {
            $period = sprintf('%04d-%02d-01', self::YEAR, $month);
            $due = $tax->advance($period, $cadence)['result']->advance . '.00';
            $paid = $month < ($quarterly ? 10 : 12) ? $due : null;
            $date = (new \DateTimeImmutable($period))->modify($quarterly ? '+3 months' : '+1 month')->format('Y-m-20');
            self::obligation($db, $clock, 'ryczalt_advance', 'ryczalt_advance.' . $cadence, $period, $date, $due, $paid);
        }
        JpkV7mTestTools::credential($db, $clock);
        $ewp = (string) UuidV7::generate($clock->now());
        $db->insert('filing', ['id' => $ewp,'environment' => 'test','type' => 'JPK_EWP','period_start' => '2025-01-01','period_end' => '2025-12-31','purpose' => 1,'seq' => 0,'status' => 'accepted',
            'schema_version' => 'JPK_EWP(4)','totals' => json_encode(['sum' => self::REVENUE_TOTAL], JSON_THROW_ON_ERROR),'created_at' => $stamp,'updated_at' => $stamp]);
        return ['ewp' => $ewp,'obligation' => self::obligation($db, $clock, 'pit_28', 'pit_28.yearly', '2025-01-01', '2026-04-30', null, null)];
    }

    private static function obligation(Connection $db, MockClock $clock, string $type, string $rule, string $period, string $date, ?string $due, ?string $paid): string
    {
        $id = (string) UuidV7::generate($clock->now());
        $stamp = $clock->now()->format('Y-m-d\TH:i:s.uP');
        $db->insert('obligation', ['id' => $id,'environment' => 'test','type' => $type,'rule_key' => $rule,'period_start' => $period,'nominal_due_date' => $date,'due_date' => $date,'amount_due' => $due,
            'status' => $paid === null ? 'open' : 'done','done_at' => $paid === null ? null : $stamp,'amount_paid' => $paid,'paid_on' => $paid === null ? null : $date,
            'dedupe_key' => 'fictional-pit28-2025:' . $id,'created_at' => $stamp,'updated_at' => $stamp]);
        return $id;
    }
}
