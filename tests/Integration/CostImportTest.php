<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Cost\ManualCost;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use Symfony\Component\Clock\MockClock;

/** Import kosztów z KSeF i wpis ręczny spoza KSeF na fikcyjnych fakturach (docs/data-model.md, „Faktura kosztowa”). */
final class CostImportTest extends DatabaseTestCase
{
    public function testKsefCostIsImportedOnceWithLinesAndDocument(): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        $costs = F1TestTools::costs($this->db(), $clock);
        $xml = F1TestTools::fa3(['lines' => [['net' => '100.00','code' => '23','vat' => '23.00'], ['net' => '50.00','code' => '23','vat' => '11.50']]]);
        $id = $costs->importKsef($xml, 'FIKCYJNY-KSEF-0001', new \DateTimeImmutable('2026-11-02T10:05:00Z'));
        self::assertSame($id, $costs->importKsef($xml, 'FIKCYJNY-KSEF-0001', new \DateTimeImmutable('2026-11-02T10:05:00Z')));
        $row = $this->db()->fetchAssociative('SELECT * FROM invoice WHERE id=?', [$id]);
        self::assertIsArray($row);
        self::assertSame(['cost','imported','ksef','150.00','34.50','184.50','150.00','34.50','2026-11-05','FA(3) 1-0E'], [$row['direction'], $row['cost_status'], $row['source'], $row['net_total'], $row['vat_total'], $row['gross_total'], $row['net_pln'], $row['vat_pln'], $row['tax_point_date'], $row['schema_version']]);
        self::assertSame(2, F1TestTools::count($this->db(), 'SELECT count(*) FROM invoice_line WHERE invoice_id=?', [$id]));
        self::assertSame(1, F1TestTools::count($this->db(), "SELECT count(*) FROM stored_file WHERE owner_table='invoice' AND owner_id=? AND kind='cost_document'", [$id]));
        $supplier = $this->db()->fetchAssociative('SELECT nip, is_supplier, is_client FROM counterparty WHERE id=?', [$row['counterparty_id']]);
        self::assertSame(['nip' => F1TestTools::SUPPLIER_NIP,'is_supplier' => true,'is_client' => false], $supplier);
    }

    public function testDifferentLineDatesLeaveTaxPointEmpty(): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        $xml = F1TestTools::fa3(['service' => null,'lines' => [['net' => '100.00','code' => '23','vat' => '23.00','date' => '2026-10-30'], ['net' => '100.00','code' => '23','vat' => '23.00','date' => '2026-11-03']]]);
        $id = F1TestTools::costs($this->db(), $clock)->importKsef($xml, 'FIKCYJNY-KSEF-0002', new \DateTimeImmutable('2026-11-04T10:00:00Z'));
        self::assertNull($this->db()->fetchOne('SELECT tax_point_date FROM invoice WHERE id=?', [$id]));
        $owner = F1TestTools::owner($this->db(), $clock);
        $this->expectExceptionMessage('różne daty');
        F1TestTools::review($this->db(), $clock)->review($id, 'business', $owner);
    }

    public function testManualCostEntryRequiresDocumentFile(): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        $owner = F1TestTools::owner($this->db(), $clock);
        $costs = F1TestTools::costs($this->db(), $clock);
        try {
            $costs->recordManual(self::manual('paper'), '', 'application/pdf', $owner);
            self::fail('Koszt bez pliku powstał.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('wymaga pliku', $e->getMessage());
        }
        self::assertSame(0, F1TestTools::count($this->db(), "SELECT count(*) FROM invoice WHERE direction='cost'"));
        $id = $costs->recordManual(self::manual('paper'), "%PDF-1.4\n% fikcyjny paragon\n", 'application/pdf', $owner);
        self::assertSame(['paper','outside_ksef','2026-11-03'], array_values($this->db()->fetchAssociative('SELECT source, ksef_issue_mode, received_on FROM invoice WHERE id=?', [$id]) ?: []));
        self::assertSame(1, F1TestTools::count($this->db(), "SELECT count(*) FROM stored_file WHERE owner_id=? AND kind='cost_document' AND media_type='application/pdf'", [$id]));
        EvidenceArtefacts::write('cmd/manual-cost-requires-file.txt', EvidenceArtefacts::csv($this->db()->fetchAllAssociative("SELECT i.invoice_number, i.source, i.cost_status, f.sha256 FROM invoice i JOIN stored_file f ON f.owner_id=i.id AND f.kind='cost_document' WHERE i.id=?", [$id])));
        // Drugi wpis tego samego dokumentu jest odrzucony.
        $this->expectExceptionMessage('już jest');
        $costs->recordManual(self::manual('paper'), "%PDF-1.4\n% fikcyjny paragon\n", 'application/pdf', $owner);
    }

    public function testManualCostDedupMergesKsefInvoiceWithManualEntry(): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        $owner = F1TestTools::owner($this->db(), $clock);
        $costs = F1TestTools::costs($this->db(), $clock);
        $review = F1TestTools::review($this->db(), $clock);
        // Ta sama kwota: wiersz dostaje numer KSeF i zostaje w swoim stanie.
        $same = $costs->recordManual(self::manual('email'), "%PDF-1.4\n% fikcyjna faktura\n", 'application/pdf', $owner);
        $review->review($same, 'business', $owner);
        $linked = $costs->importKsef(F1TestTools::fa3(), 'FIKCYJNY-KSEF-0003', new \DateTimeImmutable('2026-11-02T10:05:00Z'));
        self::assertSame($same, $linked);
        self::assertSame(['FIKCYJNY-KSEF-0003','reviewed'], array_values($this->db()->fetchAssociative('SELECT ksef_number, cost_status FROM invoice WHERE id=?', [$same]) ?: []));
        // Inna kwota: KSeF jest wiążący, koszt wraca do `imported`.
        $other = $costs->recordManual(self::manual('email', 'FV/2/11/2026', '900.00', '207.00', '1107.00'), "%PDF-1.4\n% fikcyjna faktura 2\n", 'application/pdf', $owner);
        $review->review($other, 'business', $owner);
        $review->assign($other, null, $owner);
        $costs->importKsef(F1TestTools::fa3(['number' => 'FV/2/11/2026']), 'FIKCYJNY-KSEF-0004', new \DateTimeImmutable('2026-11-02T10:05:00Z'));
        $row = $this->db()->fetchAssociative('SELECT ksef_number, cost_status, net_total, vat_period_start FROM invoice WHERE id=?', [$other]);
        self::assertSame(['ksef_number' => 'FIKCYJNY-KSEF-0004','cost_status' => 'imported','net_total' => '1000.00','vat_period_start' => null], $row);
        self::assertSame(2, F1TestTools::count($this->db(), "SELECT count(*) FROM invoice WHERE direction='cost'"));
        self::assertSame(1, F1TestTools::count($this->db(), "SELECT count(*) FROM audit_log WHERE action='cost.ksef_linked' AND details->>'amounts_changed'='true'"));
        EvidenceArtefacts::write('cmd/manual-cost-dedup.txt', EvidenceArtefacts::csv($this->db()->fetchAllAssociative("SELECT invoice_number, source, ksef_number, cost_status, net_total FROM invoice WHERE direction='cost' ORDER BY invoice_number")));
    }

    public function testSupplierMatchByNipAddsRoleOrCreatesRow(): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        $client = F1TestTools::directory($this->db(), $clock)->client(F1TestTools::reader()->read(F1TestTools::fa3(['buyer' => F1TestTools::plParty(F1TestTools::CLIENT_NIP, 'Fikcyjny klient')]))->buyer);
        $costs = F1TestTools::costs($this->db(), $clock);
        // Faktura kosztowa od istniejącego klienta: ten sam wiersz dostaje `is_supplier`.
        $first = $costs->importKsef(F1TestTools::fa3(['seller' => F1TestTools::plParty(F1TestTools::CLIENT_NIP, 'Fikcyjny klient')]), 'FIKCYJNY-KSEF-0011', new \DateTimeImmutable('2026-11-02T10:05:00Z'));
        self::assertSame($client, $this->db()->fetchOne('SELECT counterparty_id FROM invoice WHERE id=?', [$first]));
        // Nowy sprzedawca: nowy wiersz z `is_supplier`.
        $second = $costs->importKsef(F1TestTools::fa3(['number' => 'FV/9/11/2026']), 'FIKCYJNY-KSEF-0012', new \DateTimeImmutable('2026-11-02T10:05:00Z'));
        $rows = $this->db()->fetchAllAssociative('SELECT nip, is_client, is_supplier FROM counterparty ORDER BY nip');
        self::assertSame([['nip' => F1TestTools::SUPPLIER_NIP,'is_client' => false,'is_supplier' => true], ['nip' => F1TestTools::CLIENT_NIP,'is_client' => true,'is_supplier' => true]], $rows);
        self::assertNotSame($client, $this->db()->fetchOne('SELECT counterparty_id FROM invoice WHERE id=?', [$second]));
        EvidenceArtefacts::write('cmd/supplier-match.txt', EvidenceArtefacts::csv($rows));
    }

    private static function manual(string $source, string $number = 'FV/1/11/2026', string $net = '1000.00', string $vat = '230.00', string $gross = '1230.00'): ManualCost
    {
        return new ManualCost($source, null, ['name' => 'Fikcyjny dostawca sp. z o.o.','country' => 'PL','nip' => F1TestTools::SUPPLIER_NIP], $number, '2026-11-02', '2026-11-05', '2026-11-03', 'PLN', $net, $vat, $gross);
    }
}
