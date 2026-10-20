<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Audit\AuditLog;
use App\Security\Redactor;
use App\Setting\SettingKeys;
use App\Tests\Support\JpkV7mTestTools;
use App\Tests\Support\ObligationTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/**
 * Obowiązki z danych encji (F3.2, F5.4): `wht_payment`, `wht_certificate` i `invoice_issue` na granicy horyzontu, kwota WHT
 * w pełnych złotych i `cit_10z` tylko z wypłat roku. Kontrahenci, faktury i stawki fikcyjne.
 */
final class ObligationEngineSupplierTest extends DatabaseTestCase
{
    private const RATES = ['standard' => ['license' => '20.00', 'services_21_2a' => '20.00'], 'treaty' => ['US' => ['license' => '10.00', 'services_21_2a' => '0.00']]];

    public function testWhtPaymentDueOnLastDayOfHorizon(): void
    {
        // Horyzont od 8.10.2026 kończy się 8.11.2027; wypłata z 10.2027 ma termin 7.11.2027 (niedziela) → 8.11.2027.
        $clock = new MockClock('2026-10-08T04:00:00Z');
        $this->rates($clock);
        $supplier = $this->supplier($clock, 'Fictional Licensor Inc.', null);
        $this->paid($clock, $supplier, 'LIC-2027-10', '1000.00', '2027-10-15');
        $due = array_column(ObligationTestTools::engine($this->db(), $clock)->candidates('2026-10-08', '2027-11-08'), 'due_date', 'dedupe_key');
        self::assertSame('2027-11-08', $due['wht_payment:2027-10-01:' . $supplier]);
    }

    public function testWhtAmountIsRoundedToWholeZlotyAndCertificateCountsOnPaymentDay(): void
    {
        $clock = new MockClock('2026-11-20T09:00:00Z');
        $this->rates($clock);
        // Certyfikat ważny dokładnie do dnia wypłaty: stawka z umowy 10%.
        $certified = $this->supplier($clock, 'Fictional Licensor Inc.', '2026-11-10');
        $this->paid($clock, $certified, 'LIC-2026-11', '1000.00', '2026-11-10');
        // 1234,56 zł × 20% = 246,912 zł → 247 zł.
        $standard = $this->supplier($clock, 'Fictional Tools Inc.', null);
        $this->paid($clock, $standard, 'TLS-2026-11', '1234.56', '2026-11-10');
        $engine = ObligationTestTools::engine($this->db(), $clock);
        $engine->recompute();
        self::assertSame('100.00', $this->amount($certified));
        self::assertSame('247.00', $this->amount($standard));
        // Kwota w kanonicznej postaci: drugi przebieg niczego nie zmienia.
        self::assertSame(0, $engine->recompute()['updated']);
    }

    public function testCit10zCountsOnlyPaymentsOfItsYear(): void
    {
        $clock = new MockClock('2026-10-12T04:00:00Z');
        $this->rates($clock);
        $supplier = $this->supplier($clock, 'Fictional Licensor Inc.', null);
        $this->paid($clock, $supplier, 'LIC-2027-01', '1000.00', '2027-01-15');
        $statuses = array_column(ObligationTestTools::engine($this->db(), $clock)->candidates('2026-10-12', '2027-11-12'), 'status', 'dedupe_key');
        self::assertSame('open', $statuses['wht_payment:2027-01-01:' . $supplier]);
        self::assertSame('not_applicable', $statuses['cit_10z:2026-01-01']);
    }

    public function testWhtCertificateDateSourcesAndHorizon(): void
    {
        $clock = new MockClock('2026-10-12T04:00:00Z');
        $onLastDay = $this->supplier($clock, 'Fictional Licensor Inc.', '2027-11-12');
        $beyond = $this->supplier($clock, 'Fictional Media Inc.', '2027-11-13');
        $treatySet = $this->supplier($clock, 'Fictional Data Inc.', null);
        $withoutDate = $this->supplier($clock, 'Fictional Tools Inc.', null);
        // Kraj umowy ustawiony 2.10.2026 o 0:30 w Europe/Warsaw (1.10.2026 w UTC).
        (new AuditLog($this->db(), new MockClock('2026-10-01T22:30:00Z'), new Redactor()))->append('counterparty.wht_treaty_country_set', ['country' => 'US'], 'test', 'counterparty', $treatySet);
        $certificates = array_values(array_filter(ObligationTestTools::engine($this->db(), $clock)->candidates('2026-10-12', '2027-11-12'), static fn(array $item): bool => $item['type'] === 'wht_certificate'));
        $keys = array_column($certificates, 'due_date', 'dedupe_key');
        ksort($keys);
        self::assertSame(['wht_certificate:2026-10-02:' . $treatySet => '2026-10-02', 'wht_certificate:2027-11-12:' . $onLastDay => '2027-11-12'], $keys);
        self::assertNotContains($beyond, array_column($certificates, 'counterparty_id'));
        self::assertNotContains($withoutDate, array_column($certificates, 'counterparty_id'));
    }

    public function testInvoiceIssuePerDraftOnLastDayOfHorizon(): void
    {
        // Horyzont od 15.10.2026 kończy się 15.11.2027: szkic za usługę z 10.2027 ma termin dokładnie tego dnia.
        $clock = new MockClock('2026-10-15T04:00:00Z');
        $client = $this->supplier($clock, 'Fictional Client GmbH', null);
        $late = $this->draft($clock, $client, 'FV/2027/10/1', '2027-10-20');
        $early = $this->draft($clock, $client, 'FV/2026/10/1', '2026-10-05');
        $issues = array_values(array_filter(ObligationTestTools::engine($this->db(), $clock)->candidates('2026-10-15', '2027-11-15'), static fn(array $item): bool => $item['rule_key'] === 'invoice_issue.draft'));
        $due = array_column($issues, 'due_date', 'dedupe_key');
        ksort($due);
        self::assertSame(['invoice_issue:2026-10-01:' . $early => '2026-11-15', 'invoice_issue:2027-10-01:' . $late => '2027-11-15'], $due);
    }

    private function rates(MockClock $clock): void
    {
        ObligationTestTools::settings($this->db(), $clock)->set(SettingKeys::WHT_RATES, self::RATES, '2026-01-01', null, 'fictional');
    }

    private function supplier(MockClock $clock, string $name, ?string $certificate): string
    {
        $id = (string) UuidV7::generate($clock->now());
        $stamp = $clock->now()->format('c');
        $this->db()->insert('counterparty', ['id' => $id, 'environment' => 'test', 'name' => $name, 'country' => 'US', 'foreign_tax_id' => '98-' . substr(md5($name), 0, 7), 'address' => '{}', 'bank_accounts' => '[]',
            'is_client' => 'true', 'is_supplier' => 'true', 'is_natural_person' => 'false', 'wht_category' => 'license', 'wht_treaty_country' => 'US', 'wht_cert_valid_until' => $certificate, 'created_at' => $stamp, 'updated_at' => $stamp]);
        return $id;
    }

    private function paid(MockClock $clock, string $supplier, string $number, string $gross, string $paidOn): void
    {
        JpkV7mTestTools::invoice($this->db(), $clock, ['direction' => 'cost', 'cost_status' => 'reviewed', 'cost_review' => 'business', 'source' => 'email', 'ksef_issue_mode' => 'outside_ksef', 'invoice_number' => $number,
            'counterparty_id' => $supplier, 'currency' => 'PLN', 'issue_date' => substr($paidOn, 0, 8) . '01', 'net_total' => $gross, 'vat_total' => '0.00', 'gross_total' => $gross, 'paid_on' => $paidOn,
            'vat_exempt' => 'false', 'deductible' => 'true', 'is_fixed_asset' => 'false']);
    }

    private function draft(MockClock $clock, string $client, string $number, string $serviceDate): string
    {
        return JpkV7mTestTools::invoice($this->db(), $clock, ['direction' => 'sale', 'status' => 'draft', 'invoice_number' => $number, 'counterparty_id' => $client, 'currency' => 'PLN', 'issue_date' => '2026-10-15',
            'service_date' => $serviceDate, 'net_total' => '100.00', 'vat_total' => '0.00', 'gross_total' => '100.00']);
    }

    private function amount(string $supplier): mixed
    {
        return $this->db()->fetchOne("SELECT amount_due FROM obligation WHERE type='wht_payment' AND counterparty_id=?", [$supplier]);
    }
}
