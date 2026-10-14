<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\ServiceMap;
use App\Setting\SettingKeys;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\LedgerTestTools;
use App\Tests\Support\ObligationTestTools;
use Symfony\Component\Clock\MockClock;

/** Sprzedaż z KSeF Subject1 w ewidencji (F2.2): pola faktury, kurs PIT, stawki i wiersze `regular`. Dane fikcyjne. */
final class LedgerSubject1Test extends DatabaseTestCase
{
    private const MAP = [
        ['service_type' => 'software','freshbooks_item' => 'Development','p7_match' => ['usługa 1'],'pkwiu' => '62.01.1','rate_pct' => '12.00','default' => false],
        ['service_type' => 'other','freshbooks_item' => null,'p7_match' => ['usługa 2'],'pkwiu' => '82.99.1','rate_pct' => '8.50','default' => false],
    ];

    public function testLedgerTwoRatesGiveOneRegularRowPerRateWithContinuousLp(): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        $this->settings($clock, self::MAP, '2026-01-01');
        $xml = F1TestTools::fa3(['number' => 'FV/2/11/2026','issue' => '2026-11-02','service' => '2026-10-30','buyer' => F1TestTools::plParty(F1TestTools::CLIENT_NIP, 'Fikcyjny klient sp. z o.o.'),
            'seller' => F1TestTools::plParty(F1TestTools::OWN_NIP, 'Fikcyjna JDG'),'lines' => [['net' => '1000.00','code' => '23','vat' => '230.00'],['net' => '200.00','code' => '23','vat' => '46.00']]]);
        $id = F1TestTools::subject1($this->db(), $clock)->import($xml, 'FIKCYJNY-KSEF-0301', new \DateTimeImmutable('2026-11-02T10:05:00Z'));
        $rows = LedgerTestTools::rows($this->db());
        self::assertSame([[1, '12.00', '1000.00'], [2, '8.50', '200.00']], array_map(static fn(array $row): array => [$row['lp'], $row['k_9'], $row['k_8']], $rows));
        foreach ($rows as $row) {
            self::assertSame(['2026-12-10', '2026-10-30', 'FV/2/11/2026', 'FIKCYJNY-KSEF-0301', 'PL', F1TestTools::CLIENT_NIP, 'ksef_subject1', 'regular'], [$row['k_2'], $row['k_3'], $row['k_4'], $row['k_5'], $row['k_6'], $row['k_7'], $row['source'], $row['kind']]);
        }
        $lines = $this->db()->fetchAllAssociative('SELECT service_type, pkwiu, ryczalt_rate_pct FROM invoice_line WHERE invoice_id=? ORDER BY line_no', [$id]);
        self::assertSame([['software', '62.01.1', '12.00'], ['other', '82.99.1', '8.50']], array_map('array_values', $lines));
        // Ponowne przejście nie dopisuje drugiego zestawu wierszy.
        self::assertSame('duplicate', F1TestTools::ledger($this->db(), $clock)->apply($id, true)->outcome);
        self::assertCount(2, LedgerTestTools::rows($this->db()));
        EvidenceArtefacts::write('golden/ledger-two-rates.txt', EvidenceArtefacts::csv($rows));
    }

    public function testLedgerSubject1FxSetsPitRateOnInvoiceAndEntry(): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        $this->settings($clock, [['service_type' => 'software','freshbooks_item' => null,'p7_match' => [],'pkwiu' => '62.01.1','rate_pct' => '12.00','default' => true]], '2026-01-01');
        // P_6 30.10.2026 (piątek), P_1 2.11.2026: przychód 30.10, kurs PIT z tabeli z 29.10 (docs/tax-rules.md, przykład).
        $xml = F1TestTools::fa3(['currency' => 'EUR','issue' => '2026-11-02','service' => '2026-10-30','seller' => F1TestTools::plParty(F1TestTools::OWN_NIP, 'Fikcyjna JDG'),
            'buyer' => F1TestTools::euParty('DE', '999999999', 'Fikcyjny klient GmbH'),'lines' => [['net' => '1000.00','code' => 'np II','vat' => '0.00']]]);
        $id = F1TestTools::subject1($this->db(), $clock, ['EUR' => ['2026-10-29' => '4.2500','2026-10-30' => '4.2700']])->import($xml, 'FIKCYJNY-KSEF-0302', new \DateTimeImmutable('2026-11-02T10:05:00Z'));
        $invoice = $this->db()->fetchAssociative('SELECT i.revenue_date, i.fx_pit_rate_id, f.effective_date FROM invoice i JOIN fx_rate f ON f.id=i.fx_pit_rate_id WHERE i.id=?', [$id]);
        $entry = $this->db()->fetchAssociative('SELECT revenue_date, fx_rate_id, amount_pln, original_amount, original_currency, counterparty_country, counterparty_tax_id FROM revenue_entry WHERE invoice_id=?', [$id]);
        self::assertIsArray($invoice);
        self::assertIsArray($entry);
        self::assertSame(['2026-10-30', '2026-10-29'], [$invoice['revenue_date'], $invoice['effective_date']]);
        self::assertSame($invoice['fx_pit_rate_id'], $entry['fx_rate_id']);
        self::assertSame(['2026-10-30', '4250.00', '1000.00', 'EUR', 'DE', '999999999'], [$entry['revenue_date'], $entry['amount_pln'], $entry['original_amount'], $entry['original_currency'], $entry['counterparty_country'], $entry['counterparty_tax_id']]);
        self::assertSame('12.00', $this->db()->fetchOne('SELECT ryczalt_rate_pct FROM invoice_line WHERE invoice_id=?', [$id]));
        EvidenceArtefacts::write('db/ledger-subject1-fx-invoice.csv', EvidenceArtefacts::csv([['id' => $id,'revenue_date' => $invoice['revenue_date'],'fx_pit_rate_id' => $invoice['fx_pit_rate_id']]]));
        EvidenceArtefacts::write('db/ledger-subject1-fx-revenue_entry.csv', EvidenceArtefacts::csv([$entry]));
    }

    public function testLedgerAutoEntriesOffFillsInvoiceFieldsOnly(): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        $this->settings($clock, self::MAP, '2026-01-01', '2027-01-01');
        $xml = F1TestTools::fa3(['number' => 'FV/3/11/2026','issue' => '2026-11-02','service' => '2026-11-02','seller' => F1TestTools::plParty(F1TestTools::OWN_NIP, 'Fikcyjna JDG'),
            'buyer' => F1TestTools::plParty(F1TestTools::CLIENT_NIP, 'Fikcyjny klient sp. z o.o.')]);
        $id = F1TestTools::subject1($this->db(), $clock)->import($xml, 'FIKCYJNY-KSEF-0303', new \DateTimeImmutable('2026-11-02T10:05:00Z'));
        self::assertSame('2026-11-02', $this->db()->fetchOne('SELECT revenue_date FROM invoice WHERE id=?', [$id]));
        self::assertSame('12.00', $this->db()->fetchOne('SELECT ryczalt_rate_pct FROM invoice_line WHERE invoice_id=?', [$id]));
        self::assertSame(0, F1TestTools::count($this->db(), 'SELECT count(*) FROM revenue_entry'));
    }

    public function testLedgerReconstructWritesLateK2WithJanuaryRevenueDate(): void
    {
        $clock = new MockClock('2026-02-10T09:00:00Z');
        $this->settings($clock, self::MAP, '2026-01-01', '2027-01-01');
        $xml = F1TestTools::fa3(['number' => 'FV/1/02/2026','issue' => '2026-02-02','service' => '2026-01-30','seller' => F1TestTools::plParty(F1TestTools::OWN_NIP, 'Fikcyjna JDG'),
            'buyer' => F1TestTools::plParty(F1TestTools::CLIENT_NIP, 'Fikcyjny klient sp. z o.o.')]);
        F1TestTools::subject1($this->db(), $clock)->import($xml, 'FIKCYJNY-KSEF-0304', new \DateTimeImmutable('2026-02-02T10:05:00Z'));
        self::assertSame(0, F1TestTools::count($this->db(), 'SELECT count(*) FROM revenue_entry'));
        $clock->modify('2026-11-20T09:00:00Z');
        self::assertSame(['written' => 1], F1TestTools::ledger($this->db(), $clock)->reconstruct(2026, 'test'));
        self::assertSame(['duplicate' => 1], F1TestTools::ledger($this->db(), $clock)->reconstruct(2026, 'test'));
        $rows = LedgerTestTools::rows($this->db());
        self::assertSame([['2026-11-20', '2026-01-30']], array_map(static fn(array $row): array => [$row['k_2'], $row['k_3']], $rows));
        EvidenceArtefacts::write('db/ledger-reconstruct-late-k2-revenue_entry.csv', EvidenceArtefacts::csv($rows));
    }

    public function testRyczaltServiceMapMissingLeavesInvoiceWithoutEntry(): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        $this->settings($clock, [self::MAP[1]], '2026-01-01');
        $xml = F1TestTools::fa3(['number' => 'FV/4/11/2026','service' => '2026-11-02','seller' => F1TestTools::plParty(F1TestTools::OWN_NIP, 'Fikcyjna JDG'),
            'buyer' => F1TestTools::plParty(F1TestTools::CLIENT_NIP, 'Fikcyjny klient sp. z o.o.'),'lines' => [['net' => '1000.00','code' => '23','vat' => '230.00'],['net' => '200.00','code' => '23','vat' => '46.00']]]);
        $id = F1TestTools::subject1($this->db(), $clock)->import($xml, 'FIKCYJNY-KSEF-0305', new \DateTimeImmutable('2026-11-02T10:05:00Z'));
        self::assertSame('rate_missing', F1TestTools::ledger($this->db(), $clock)->apply($id, true)->outcome);
        self::assertSame(0, F1TestTools::count($this->db(), 'SELECT count(*) FROM revenue_entry'));
        // Panel liczy alert zapytaniem: pozycja przyjętej sprzedaży bez stawki.
        self::assertSame(1, F1TestTools::count($this->db(), "SELECT count(*) FROM invoice_line l JOIN invoice i ON i.id=l.invoice_id WHERE i.direction='sale' AND i.kind='VAT' AND l.ryczalt_rate_pct IS NULL AND NOT l.state_before"));
    }

    public function testRevenueDateFollowsArticle14(): void
    {
        $reader = F1TestTools::reader();
        $party = ['seller' => F1TestTools::plParty(F1TestTools::OWN_NIP, 'Fikcyjna JDG'),'buyer' => F1TestTools::plParty(F1TestTools::CLIENT_NIP, 'Fikcyjny klient sp. z o.o.')];
        $date = static fn(array $o): ?string => \App\Ledger\Subject1Ledger::revenueDate($reader->read(F1TestTools::fa3($o + $party)));
        // Przykłady z docs/tax-rules.md: P_6 28.10 → 28.10; P_6 30.11 po P_1 2.11 → 2.11; OkresFa → P_6_Do; bez dat → P_1.
        self::assertSame('2026-10-28', $date(['issue' => '2026-11-02','service' => '2026-10-28']));
        self::assertSame('2026-11-02', $date(['issue' => '2026-11-02','service' => '2026-11-30']));
        self::assertSame('2026-10-31', $date(['issue' => '2026-11-02','period' => ['2026-10-01','2026-10-31']]));
        self::assertSame('2026-11-02', $date(['issue' => '2026-11-02','service' => null]));
        self::assertSame('2026-10-20', $date(['issue' => '2026-11-02','service' => null,'lines' => [['net' => '1.00','code' => '23','vat' => '0.23','date' => '2026-10-20'],['net' => '1.00','code' => '23','vat' => '0.23','date' => '2026-10-20']]]));
        self::assertNull($date(['issue' => '2026-11-02','service' => null,'lines' => [['net' => '1.00','code' => '23','vat' => '0.23','date' => '2026-10-20'],['net' => '1.00','code' => '23','vat' => '0.23','date' => '2026-10-21']]]));
    }

    public function testServiceMapRejectsFloatsAndSecondDefault(): void
    {
        foreach ([[['service_type' => 'a','freshbooks_item' => null,'p7_match' => [],'pkwiu' => '62.01.1','rate_pct' => 12.0,'default' => true]],
            [array_merge(self::MAP[0], ['default' => true]), array_merge(self::MAP[1], ['default' => true])]] as $invalid) {
            try {
                ServiceMap::normalize($invalid);
                self::fail('Niepoprawna mapa przyjęta.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $map = ServiceMap::fromSetting(self::MAP);
        self::assertSame('software', $map->match('development', null)['service_type'] ?? null);
        self::assertSame('other', $map->match(null, 'Fikcyjna usługa 2')['service_type'] ?? null);
        self::assertNull($map->match(null, 'coś innego'));
    }

    /** @param list<array<string,mixed>> $map */
    private function settings(MockClock $clock, array $map, string $mapFrom, string $autoFrom = '2026-01-01'): void
    {
        $settings = ObligationTestTools::settings($this->db(), $clock);
        $settings->set(SettingKeys::RYCZALT_SERVICE_MAP, $map, $mapFrom, null, 'przed przeglądem doradcy');
        $settings->set(SettingKeys::LEDGER_AUTO_ENTRIES, true, $autoFrom, null);
    }
}
