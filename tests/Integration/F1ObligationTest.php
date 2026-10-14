<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Cost\ManualCost;
use App\Security\SecretStore;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\MemoryMailTransport;
use App\Tests\Support\ObligationTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\UuidV7;

/** Obowiązki F1: `vat_ue` z faktur, `wht_payment` per dostawca i `wht_certificate` (docs/compliance-calendar.md). */
final class F1ObligationTest extends DatabaseTestCase
{
    public function testVatUeObligationFollowsEuSalesAndSubject1Cursor(): void
    {
        $clock = new MockClock('2026-11-20T05:00:00Z');
        ObligationTestTools::seed($this->db(), $clock);
        $engine = ObligationTestTools::engine($this->db(), $clock);
        $own = F1TestTools::plParty(F1TestTools::OWN_NIP, 'Fikcyjna JDG');
        $subject1 = F1TestTools::subject1($this->db(), $clock);
        $november = $subject1->import(F1TestTools::fa3(['number' => 'FV/1/11/2026','issue' => '2026-11-05','service' => '2026-11-05','seller' => $own,
            'buyer' => F1TestTools::euParty('DE', '999999999', 'Fikcyjny klient GmbH'),'lines' => [['net' => '1000.00','code' => 'np II','vat' => '0.00']]]), 'FIKCYJNY-KSEF-0301', new \DateTimeImmutable('2026-11-05T10:00:00Z'));
        $october = $subject1->import(F1TestTools::fa3(['number' => 'FV/9/10/2026','issue' => '2026-10-20','service' => '2026-10-20','seller' => $own,
            'buyer' => F1TestTools::foreignParty('DE', 'FIKCYJNY-DE-1', 'Fikcyjny klient bez VAT UE'),'lines' => [['net' => '500.00','code' => 'np II','vat' => '0.00']]]), 'FIKCYJNY-KSEF-0302', new \DateTimeImmutable('2026-10-20T10:00:00Z'));
        self::assertSame(['eu_b2b','eu_b2b_unidentified'], [$this->scenario($november), $this->scenario($october)]);
        $this->cursor('2026-11-20T06:00:00+01:00');
        $engine->recompute();
        $rows = ObligationTestTools::rows($this->db());
        // 10.2026 bez sprzedaży eu_b2b, kursor za końcem 15.11: system zamyka bez `done_by`.
        self::assertSame(['not_applicable', null], [$rows['vat_ue:2026-10-01']['status'], $rows['vat_ue:2026-10-01']['done_by']]);
        self::assertSame('open', $rows['vat_ue:2026-11-01']['status']);
        // 12.2026 bez sprzedaży, ale kursor przed końcem 15.01.2027.
        self::assertSame('open', $rows['vat_ue:2026-12-01']['status']);
        EvidenceArtefacts::write('cmd/vat-ue-obligation.txt', EvidenceArtefacts::csv([
            ...$this->db()->fetchAllAssociative("SELECT dedupe_key, status, done_by, due_date FROM obligation WHERE type='vat_ue' AND period_start BETWEEN '2026-10-01' AND '2026-12-01' ORDER BY period_start"),
            ...$this->db()->fetchAllAssociative("SELECT invoice_number AS dedupe_key, tax_scenario AS status, NULL AS done_by, tax_point_date AS due_date FROM invoice WHERE direction='sale' ORDER BY invoice_number"),
        ]));
    }

    public function testVatUeLateSaleReopensMonth(): void
    {
        $clock = new MockClock('2026-11-20T05:00:00Z');
        ObligationTestTools::seed($this->db(), $clock);
        $engine = ObligationTestTools::engine($this->db(), $clock);
        $this->cursor('2026-11-15T22:59:00Z');
        $engine->recompute();
        self::assertSame('open', ObligationTestTools::rows($this->db())['vat_ue:2026-10-01']['status'], 'kursor przed końcem 15.11 w Warszawie');
        $this->cursor('2026-11-15T23:00:00Z');
        $engine->recompute();
        self::assertSame('not_applicable', ObligationTestTools::rows($this->db())['vat_ue:2026-10-01']['status']);
        $xml = F1TestTools::fa3(['issue' => '2026-10-30','service' => '2026-10-30','seller' => F1TestTools::plParty(F1TestTools::OWN_NIP, 'Fikcyjna JDG'),
            'buyer' => F1TestTools::euParty('DE', '999999999', 'Fikcyjny klient GmbH'),'lines' => [['net' => '1000.00','code' => 'np II','vat' => '0.00']]]);
        F1TestTools::subject1($this->db(), $clock)->import($xml, 'FIKCYJNY-KSEF-0303', new \DateTimeImmutable('2026-11-16T08:00:00Z'));
        $engine->recompute();
        self::assertSame('open', ObligationTestTools::rows($this->db())['vat_ue:2026-10-01']['status']);
    }

    public function testWhtPaymentObligationPerSupplier(): void
    {
        $clock = new MockClock('2026-11-20T05:00:00Z');
        ObligationTestTools::seed($this->db(), $clock);
        $company = $this->licensor('Fikcyjny licencjodawca Ltd', 'FIKCYJNY-L1', false, $clock, '2026-11-15');
        ObligationTestTools::engine($this->db(), $clock)->recompute();
        $row = ObligationTestTools::rows($this->db())['wht_payment:2026-11-01:' . $company] ?? null;
        self::assertIsArray($row);
        // 7.12.2026 to poniedziałek; tytuł z miesiącem wypłaty.
        self::assertSame(['2026-12-07','open','CIT-10Z 26M11',$company,'wht_payment.supplier'], [$row['due_date'], $row['status'], $row['payment_title'], $row['counterparty_id'], $row['rule_key']]);
        EvidenceArtefacts::write('cmd/wht-payment-obligation.txt', EvidenceArtefacts::csv([array_intersect_key($row, array_flip(['dedupe_key','rule_key','due_date','status','payment_title']))]));
    }

    public function testWhtNaturalPersonCreatesNoWhtPayment(): void
    {
        $clock = new MockClock('2026-11-20T05:00:00Z');
        ObligationTestTools::seed($this->db(), $clock);
        $person = $this->licensor('Fikcyjna osoba', 'FIKCYJNY-L2', true, $clock, '2026-11-15');
        ObligationTestTools::engine($this->db(), $clock)->recompute();
        $rows = $this->db()->fetchAllAssociative("SELECT dedupe_key, type, status FROM obligation WHERE counterparty_id=? ORDER BY dedupe_key", [$person]);
        self::assertSame([], array_filter($rows, static fn(array $row): bool => $row['type'] === 'wht_payment'));
        EvidenceArtefacts::write('cmd/wht-natural-person.txt', EvidenceArtefacts::csv($rows === [] ? [['dedupe_key' => '','type' => 'brak wht_payment','status' => '']] : $rows));
    }

    public function testWhtCertificateObligationRemindsThirtyDaysBefore(): void
    {
        $clock = new MockClock('2026-11-20T05:00:00Z');
        ObligationTestTools::seed($this->db(), $clock);
        $company = $this->licensor('Fikcyjny licencjodawca Ltd', 'FIKCYJNY-L3', false, $clock, '2026-11-15');
        // Bez daty ważności: data bazowa to pierwsza wypłata.
        ObligationTestTools::engine($this->db(), $clock)->recompute();
        self::assertSame('open', ObligationTestTools::rows($this->db())['wht_certificate:2026-11-15:' . $company]['status']);
        $this->db()->update('counterparty', ['wht_cert_valid_until' => '2026-12-31'], ['id' => $company]);
        $clock->modify('2026-12-01T07:00:00Z');
        ObligationTestTools::engine($this->db(), $clock)->recompute();
        $rows = ObligationTestTools::rows($this->db());
        // Nowy certyfikat zamyka wiersz z daty bazowej jako `done`.
        self::assertSame('done', $rows['wht_certificate:2026-11-15:' . $company]['status']);
        self::assertSame(['2026-12-31','open'], [$rows['wht_certificate:2026-12-31:' . $company]['due_date'], $rows['wht_certificate:2026-12-31:' . $company]['status']]);
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        ObligationTestTools::dispatcher($this->db(), $clock, new MockHttpClient(new MockResponse('{}')), new MemoryMailTransport(), $secrets)->plan();
        $notifications = $this->db()->fetchAllAssociative("SELECT n.dedupe_key, n.category, n.scheduled_for FROM notification n JOIN obligation o ON o.id=n.obligation_id WHERE o.dedupe_key=?", ['wht_certificate:2026-12-31:' . $company]);
        self::assertCount(1, $notifications);
        self::assertIsString($notifications[0]['dedupe_key']);
        self::assertStringEndsWith(':T-30:push', $notifications[0]['dedupe_key']);
        EvidenceArtefacts::write('cmd/wht-certificate-obligation.txt', EvidenceArtefacts::csv($notifications));
    }

    private function licensor(string $name, string $taxId, bool $natural, MockClock $clock, string $paidOn): string
    {
        $owner = F1TestTools::owner($this->db(), $clock);
        $cost = new ManualCost('email', null, ['name' => $name,'country' => 'GB','foreign_tax_id' => $taxId,'is_natural_person' => $natural], 'LIC-' . $taxId, '2026-11-02', '2026-11-02', '2026-11-02', 'PLN', '500.00', '0.00', '500.00', 'other');
        $id = F1TestTools::costs($this->db(), $clock)->recordManual($cost, "%PDF-1.4\n% fikcyjna licencja\n", 'application/pdf', $owner);
        F1TestTools::review($this->db(), $clock)->markPaid($id, $paidOn, $owner);
        $counterparty = $this->db()->fetchOne('SELECT counterparty_id FROM invoice WHERE id=?', [$id]);
        self::assertIsString($counterparty);
        $this->db()->update('counterparty', ['wht_category' => 'license','wht_treaty_country' => 'GB'], ['id' => $counterparty]);
        return $counterparty;
    }

    private function scenario(string $invoiceId): mixed
    {
        return $this->db()->fetchOne('SELECT tax_scenario FROM invoice WHERE id=?', [$invoiceId]);
    }

    private function cursor(string $hwm): void
    {
        $stamp = '2026-11-10T05:00:00Z';
        $this->db()->executeStatement("INSERT INTO sync_cursor (id,environment,connector,stream,last_hwm,created_at,updated_at) VALUES (?,'test','ksef','subject1',?,?,?) ON CONFLICT (environment,connector,stream) DO UPDATE SET last_hwm=EXCLUDED.last_hwm", [(string) UuidV7::generate(), $hwm, $stamp, $stamp]);
    }
}
