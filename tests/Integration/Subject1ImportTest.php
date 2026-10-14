<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Invoice\Fa3Party;
use App\Invoice\Subject1Importer;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use Symfony\Component\Clock\MockClock;

/** Twoja sprzedaż wystawiona poza kokpitem i pobrana jako Subject1 (fikcyjne faktury FA(3)). */
final class Subject1ImportTest extends DatabaseTestCase
{
    public function testEuSaleIsAcceptedWithVatRateAndXmlSent(): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        $xml = F1TestTools::fa3(['currency' => 'EUR','issue' => '2026-11-02','service' => '2026-11-05','seller' => F1TestTools::plParty(F1TestTools::OWN_NIP, 'Fikcyjna JDG'),
            'buyer' => F1TestTools::euParty('DE', '999999999', 'Fikcyjny klient GmbH'),'lines' => [['net' => '1000.00','code' => 'np II','vat' => '0.00']]]);
        $importer = F1TestTools::subject1($this->db(), $clock, ['EUR' => ['2026-10-30' => '4.2000']]);
        $id = $importer->import($xml, 'FIKCYJNY-KSEF-0201', new \DateTimeImmutable('2026-11-02T10:05:00Z'));
        self::assertSame($id, $importer->import($xml, 'FIKCYJNY-KSEF-0201', new \DateTimeImmutable('2026-11-02T10:05:00Z')));
        $row = $this->db()->fetchAssociative('SELECT direction, status, tax_scenario, tax_point_date, vat_period_start, net_pln, buyer_snapshot FROM invoice WHERE id=?', [$id]);
        self::assertIsArray($row);
        // Faktura przed wykonaniem usługi: kurs z dnia przed P_1 (art. 31a ust. 2), piątek 30.10.2026.
        self::assertSame(['sale','accepted','eu_b2b','2026-11-05','2026-11-01','4200.00'], [$row['direction'], $row['status'], $row['tax_scenario'], $row['tax_point_date'], $row['vat_period_start'], $row['net_pln']]);
        self::assertIsString($row['buyer_snapshot']);
        $snapshot = json_decode($row['buyer_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($snapshot);
        self::assertSame('999999999', $snapshot['eu_vat_number']);
        self::assertSame(1, F1TestTools::count($this->db(), "SELECT count(*) FROM stored_file WHERE owner_id=? AND kind='xml_sent'", [$id]));
        self::assertTrue((bool) $this->db()->fetchOne('SELECT is_client FROM counterparty WHERE eu_vat_number=?', ['999999999']));
        // Przyjęta sprzedaż jest niezmienna poza dozwolonymi kolumnami.
        $this->expectException(\Doctrine\DBAL\Exception::class);
        $this->db()->update('invoice', ['net_total' => '1.00'], ['id' => $id]);
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function fxVariants(): iterable
    {
        // Faktura 1 000,00 EUR z P_1 2.11.2026 (fikcyjne kwoty i kursy, docs/tax-rules.md, „Kursy walut”).
        yield 'P_6 30.10.2026, ust. 1' => ['2026-10-30', '2026-10-29', '4250.00'];
        yield 'P_6 30.11.2026, ust. 2' => ['2026-11-30', '2026-10-30', '4270.00'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fxVariants')]
    public function testKsefSubject1FxVatFollowsArticle31a(string $serviceDate, string $table, string $netPln): void
    {
        $clock = new MockClock('2026-12-10T09:00:00Z');
        $xml = F1TestTools::fa3(['currency' => 'EUR','issue' => '2026-11-02','service' => $serviceDate,'seller' => F1TestTools::plParty(F1TestTools::OWN_NIP, 'Fikcyjna JDG'),
            'buyer' => F1TestTools::euParty('DE', '999999999', 'Fikcyjny klient GmbH'),'lines' => [['net' => '1000.00','code' => 'np II','vat' => '0.00']]]);
        $id = F1TestTools::subject1($this->db(), $clock, ['EUR' => ['2026-10-29' => '4.2500','2026-10-30' => '4.2700']])->import($xml, 'FIKCYJNY-KSEF-0210', new \DateTimeImmutable('2026-11-02T10:05:00Z'));
        $row = $this->db()->fetchAssociative('SELECT i.invoice_number, i.tax_point_date, f.effective_date, f.table_no, i.net_pln, i.vat_pln, i.fx_pit_rate_id FROM invoice i JOIN fx_rate f ON f.id=i.fx_vat_rate_id WHERE i.id=?', [$id]);
        self::assertIsArray($row);
        self::assertSame([$serviceDate, $table, $netPln, '0.00'], [$row['tax_point_date'], $row['effective_date'], $row['net_pln'], $row['vat_pln']]);
        // Kurs PIT ustawia ewidencja F2.2 niezależnie od kursu VAT (LedgerSubject1Test).
        self::assertIsString($row['fx_pit_rate_id']);
        EvidenceArtefacts::write('cmd/subject1-fx-vat-' . substr($serviceDate, 5, 2) . '.txt', EvidenceArtefacts::csv([$row]));
    }

    public function testTaxScenarioFromBuyer(): void
    {
        $party = static fn(?string $nip, ?string $prefix, ?string $vat, ?string $country, ?string $id): Fa3Party => new Fa3Party($nip, $prefix, $vat, $country, $id, false, 'Fikcyjny', ['country' => $country ?? $prefix ?? 'PL','line1' => null,'line2' => null]);
        self::assertSame('pl_b2b', Subject1Importer::scenario($party(F1TestTools::CLIENT_NIP, null, null, null, null)));
        self::assertSame('eu_b2b', Subject1Importer::scenario($party(null, 'FR', '99999999999', null, null)));
        self::assertSame('non_eu_b2b', Subject1Importer::scenario($party(null, 'XI', '999999999', null, null)));
        self::assertSame('eu_b2b_unidentified', Subject1Importer::scenario($party(null, null, null, 'DE', 'FIKCYJNY-1')));
        self::assertSame('non_eu_b2b', Subject1Importer::scenario($party(null, null, null, 'US', 'FIKCYJNY-2')));
    }
}
