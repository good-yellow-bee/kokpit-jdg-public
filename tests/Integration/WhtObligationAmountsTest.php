<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Setting\SettingKeys;
use App\Tests\Support\JpkV7mTestTools;
use App\Tests\Support\ObligationTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/**
 * Kryterium `wht-obligation-amounts` (F5.4, D-65): koszt dostawcy z `wht_category` zapłacony 10.11.2026 daje `wht_payment`
 * z `due_date` 7.12.2026 i kwotą według `wht.rates` (stawka z umowy tylko z certyfikatem ważnym w dniu wypłaty);
 * `cit_10z` za rok tylko przy sumie kwot > 0. Wpłatę, IFT-2R i CIT-10Z składasz ręcznie: zero wywołań konektorów.
 */
final class WhtObligationAmountsTest extends DatabaseTestCase
{
    private const RATES = ['standard' => ['license' => '20.00', 'services_21_2a' => '20.00'], 'treaty' => ['US' => ['license' => '10.00', 'services_21_2a' => '0.00']]];

    public function testAmountsFollowSettingRatesAndCertificate(): void
    {
        $clock = new MockClock('2026-11-20T09:00:00Z');
        $this->prepare($clock);
        $certified = $this->supplier($clock, 'Fictional Licensor Inc.', 'license', '2027-06-30');
        $expired = $this->supplier($clock, 'Fictional Tools Inc.', 'license', '2026-10-31');
        $this->paid($clock, $certified, 'LIC-2026-11', '1000.00');
        $this->paid($clock, $expired, 'TLS-2026-11', '250.00');

        ObligationTestTools::engine($this->db(), $clock)->recompute();
        // 1000 USD × 3,8000 = 3800 zł × 10% (umowa, certyfikat ważny) = 380 zł; 250 USD × 3,8 = 950 zł × 20% (certyfikat wygasł) = 190 zł.
        self::assertSame([
            [$certified, '2026-11-01', '2026-12-07', '380.00', 'open'],
            [$expired, '2026-11-01', '2026-12-07', '190.00', 'open'],
        ], [$this->wht($certified), $this->wht($expired)]);
        self::assertSame('open', $this->cit10z('2026-01-01'));
        self::assertEquals(0, $this->db()->fetchOne('SELECT count(*) FROM connector_call'));
    }

    public function testZeroTreatyRateGivesNoCit10z(): void
    {
        $clock = new MockClock('2026-11-20T09:00:00Z');
        $this->prepare($clock);
        $supplier = $this->supplier($clock, 'Fictional Data Inc.', 'services_21_2a', '2027-06-30');
        $this->paid($clock, $supplier, 'DAT-2026-11', '400.00');
        ObligationTestTools::engine($this->db(), $clock)->recompute();
        self::assertSame([$supplier, '2026-11-01', '2026-12-07', '0.00', 'open'], $this->wht($supplier));
        self::assertSame('not_applicable', $this->cit10z('2026-01-01'));

        // Bez kursu w bazie (wypłata w EUR) kwota zostaje pusta, a `cit_10z` liczy się ostrożnie jako obowiązek.
        $other = $this->supplier($clock, 'Fictional Media Inc.', 'license', null);
        $this->paid($clock, $other, 'MED-2026-11', '100.00', '2026-11-12', 'EUR');
        ObligationTestTools::engine($this->db(), $clock)->recompute();
        self::assertSame([$other, '2026-11-01', '2026-12-07', null, 'open'], $this->wht($other));
        self::assertSame('open', $this->cit10z('2026-01-01'));
    }

    private function prepare(MockClock $clock): void
    {
        $settings = ObligationTestTools::settings($this->db(), $clock);
        $settings->set(SettingKeys::WHT_RATES, self::RATES, '2026-01-01', null, 'fictional');
        foreach (['2026-11-09'] as $date) {
            $this->db()->insert('fx_rate', ['id' => (string) UuidV7::generate($clock->now()), 'source' => 'nbp', 'table_no' => 'fikcyjna ' . $date . ' (fictional)', 'currency' => 'USD', 'effective_date' => $date, 'mid' => '3.800000', 'fetched_at' => $clock->now()->format('c')]);
        }
    }

    private function supplier(MockClock $clock, string $name, string $category, ?string $certificate): string
    {
        $id = (string) UuidV7::generate($clock->now());
        $stamp = $clock->now()->format('c');
        $this->db()->insert('counterparty', ['id' => $id, 'environment' => 'test', 'name' => $name, 'country' => 'US', 'foreign_tax_id' => '98-' . substr(md5($name), 0, 7), 'address' => '{}', 'bank_accounts' => '[]',
            'is_client' => 'false', 'is_supplier' => 'true', 'is_natural_person' => 'false', 'wht_category' => $category, 'wht_treaty_country' => 'US', 'wht_cert_valid_until' => $certificate, 'created_at' => $stamp, 'updated_at' => $stamp]);
        return $id;
    }

    private function paid(MockClock $clock, string $supplier, string $number, string $gross, string $paidOn = '2026-11-10', string $currency = 'USD'): void
    {
        JpkV7mTestTools::invoice($this->db(), $clock, ['direction' => 'cost', 'cost_status' => 'reviewed', 'cost_review' => 'business', 'source' => 'email', 'ksef_issue_mode' => 'outside_ksef', 'invoice_number' => $number,
            'counterparty_id' => $supplier, 'currency' => $currency, 'issue_date' => substr($paidOn, 0, 8) . '01', 'net_total' => $gross, 'vat_total' => '0.00', 'gross_total' => $gross, 'paid_on' => $paidOn,
            'vat_exempt' => 'false', 'deductible' => 'true', 'is_fixed_asset' => 'false']);
    }

    /** @return list<mixed> */
    private function wht(string $supplier): array
    {
        $row = $this->db()->fetchAssociative("SELECT counterparty_id, period_start, due_date, amount_due, status FROM obligation WHERE type='wht_payment' AND counterparty_id=?", [$supplier]);
        return $row === false ? [] : array_values($row);
    }

    private function cit10z(string $periodStart): mixed
    {
        return $this->db()->fetchOne("SELECT status FROM obligation WHERE type='cit_10z' AND period_start=?", [$periodStart]);
    }
}
