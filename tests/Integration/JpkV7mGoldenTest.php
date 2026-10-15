<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\JpkV7mTestTools;
use App\Xml\JpkBuildRefused;

/**
 * Kryterium `jpk-v7m-golden` (F5.4, D-65): JPK_V7M(3) z fikcyjnego miesiąca 11.2026 z rekordami `NrKSeF`, `BFK` i `DI`
 * z `WEW`, sprzedażą np I, np II i 23%, importem usług z UE i spoza UE i fakturą w EUR. XML zgodny z XSD (`LIBXML_NONET`),
 * K_11 zawiera K_12, K_27–K_30 i K_42–K_43, bajty równe złotemu plikowi. Koszt `imported` blokuje budowę.
 */
final class JpkV7mGoldenTest extends JpkV7mTestCase
{
    private const GOLDEN = __DIR__ . '/../Fixtures/Jpk/golden/jpk-v7m-golden.xml';

    public function testFictionalMonthMatchesGoldenFileAndXsd(): void
    {
        $send = $this->v7m();
        $document = $this->builder($send->clock)->build(JpkV7mTestTools::PERIOD);
        EvidenceArtefacts::write('jpk/jpk-v7m-golden.xml', $document->xml);
        self::assertSame([], $document->xsdErrors);

        $sales = JpkV7mTestTools::register($document->xml, 'SprzedazWiersz');
        self::assertSame(['FV/2026/11/2', 'US-INV-7781', 'IE-2026-0042', 'FV/2026/11/3', 'WEW/2026/11/1', 'FV/2026/11/1'], array_column($sales, 'DowodSprzedazy'));
        // np II (`eu_b2b`) w EUR: K_11 zawiera K_12, data sprzedaży z obowiązku podatkowego.
        self::assertSame(['4250.00', '4250.00', '2026-11-30', 'DE', '999999999'], [$sales[5]['K_11'], $sales[5]['K_12'], $sales[5]['DataSprzedazy'], $sales[5]['KodKrajuNadaniaTIN'], $sales[5]['NrKontrahenta']]);
        self::assertSame(['1900.00', false], [$sales[0]['K_11'], isset($sales[0]['K_12'])]);
        self::assertSame(['2000.00', '460.00', '5555555555'], [$sales[3]['K_19'], $sales[3]['K_20'], $sales[3]['NrKontrahenta']]);
        // Import usług: spoza UE K_27/K_28 (`BFK`), art. 28b z UE K_29/K_30 (`BFK`), raport `WEW` zwolniony: `DI`, podatek 0,00.
        self::assertSame(['190.00', '43.70', '1'], [$sales[1]['K_27'], $sales[1]['K_28'], $sales[1]['BFK']]);
        self::assertSame(['424.00', '97.52', '1', 'IE'], [$sales[2]['K_29'], $sales[2]['K_30'], $sales[2]['BFK'], $sales[2]['KodKrajuNadaniaTIN']]);
        self::assertSame(['30.00', '0.00', '1', 'WEW'], [$sales[4]['K_27'], $sales[4]['K_28'], $sales[4]['DI'], $sales[4]['TypDokumentu']]);
        self::assertSame('1111111111-20261105-0A1B2C-3D4E5F-B2', $sales[0]['NrKSeF']);

        $purchases = JpkV7mTestTools::register($document->xml, 'ZakupWiersz');
        self::assertSame(['FZ/2026/11/77', 'US-INV-7781', 'IE-2026-0042', 'P/2026/11/5', 'FZ/2026/11/9'], array_column($purchases, 'DowodZakupu'));
        self::assertSame(['7777777777-20261105-1A2B3C-4D5E6F-D4', '1000.00', '230.00'], [$purchases[0]['NrKSeF'], $purchases[0]['K_42'], $purchases[0]['K_43']]);
        self::assertSame(['1', '1', '1', '1'], [$purchases[1]['BFK'], $purchases[2]['BFK'], $purchases[3]['BFK'], $purchases[4]['DI']]);
        self::assertSame(['424.00', '97.52', '2026-11-10'], [$purchases[2]['K_42'], $purchases[2]['K_43'], $purchases[2]['DataWplywu']]);

        self::assertSame(['6', '601.22', '5', '440.22'], [
            JpkV7mTestTools::value($document->xml, '/v:JPK/v:Ewidencja/v:SprzedazCtrl/v:LiczbaWierszySprzedazy'), JpkV7mTestTools::value($document->xml, '/v:JPK/v:Ewidencja/v:SprzedazCtrl/v:PodatekNalezny'),
            JpkV7mTestTools::value($document->xml, '/v:JPK/v:Ewidencja/v:ZakupCtrl/v:LiczbaWierszyZakupow'), JpkV7mTestTools::value($document->xml, '/v:JPK/v:Ewidencja/v:ZakupCtrl/v:PodatekNaliczony'),
        ]);
        self::assertSame(JpkV7mTestTools::expectedDeclaration(), JpkV7mTestTools::declaration($document->xml));
        self::assertSame(['6150.00', '4250.00', '150', 'opening'], [$document->totals['k11'], $document->totals['k12'], $document->totals['p39'], $document->totals['p39_source']]);
        self::assertSame([['sales', 5, 'WEW/2026/11/1', 'wew'], ['purchase', 5, 'FZ/2026/11/9', 'offline_without_number']], array_map(static fn(array $item): array => [$item['register'], $item['lp'], $item['document'], $item['reason']], $document->diItems));

        $golden = file_get_contents(self::GOLDEN);
        self::assertIsString($golden, 'Brak złotego pliku jpk-v7m-golden.xml');
        $diff = $golden === $document->xml ? '' : "jpk-v7m-golden.xml\n" . self::diff($golden, $document->xml);
        EvidenceArtefacts::write('golden/jpk-v7m-golden.diff', $diff);
        self::assertSame('', $diff, 'JPK_V7M różni się od złotego pliku.');
    }

    public function testImportedCostBlocksBuild(): void
    {
        $send = $this->v7m();
        $supplier = $this->db()->fetchOne("SELECT id FROM counterparty WHERE nip='7777777777'");
        JpkV7mTestTools::invoice($this->db(), $send->clock, ['direction' => 'cost', 'cost_status' => 'imported', 'source' => 'ksef', 'ksef_number' => '7777777777-20261125-1A2B3C-4D5E6F-E5',
            'invoice_number' => 'FZ/2026/11/99', 'counterparty_id' => $supplier, 'currency' => 'PLN', 'issue_date' => '2026-11-25', 'net_total' => '10.00', 'vat_total' => '2.30', 'gross_total' => '12.30',
            'vat_exempt' => 'false', 'deductible' => 'true', 'is_fixed_asset' => 'false']);
        $this->expectException(JpkBuildRefused::class);
        $this->expectExceptionMessage('FZ/2026/11/99');
        $this->builder($send->clock)->build(JpkV7mTestTools::PERIOD);
    }

    private static function diff(string $expected, string $actual): string
    {
        $expectedLines = explode("\n", $expected);
        $actualLines = explode("\n", $actual);
        $out = '';
        foreach ($expectedLines as $index => $line) {
            if (($actualLines[$index] ?? null) !== $line) {
                $out .= sprintf("@@ %d\n- %s\n+ %s\n", $index + 1, $line, $actualLines[$index] ?? '');
            }
        }
        for ($index = count($expectedLines); $index < count($actualLines); $index++) {
            $out .= sprintf("@@ %d\n+ %s\n", $index + 1, $actualLines[$index]);
        }
        return $out;
    }
}
