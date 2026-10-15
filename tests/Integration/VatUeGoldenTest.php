<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\VatUeTestTools;
use App\Xml\VatUeBuilder;
use App\Xml\XsdCatalog;
use App\Xml\XsdValidator;

/**
 * Kryterium `vat-ue-golden` (F5.5, D-66): VAT-UE(5) za 11.2026 z fikcyjnego miesiąca zgodny z XSD offline i bajt w bajt
 * ze złotym plikiem: pozycje per kraj i numer VAT UE nabywcy, P_Uc w pełnych złotych, KOR ze znakiem różnicy w grupie
 * nabywcy, `eu_b2b_unidentified` poza plikiem, bez `DaneAutoryzujace`. Dane fikcyjne.
 */
final class VatUeGoldenTest extends VatUeTestCase
{
    private const GOLDEN = __DIR__ . '/../Fixtures/Jpk/golden/vat-ue-golden.xml';

    public function testVatUeMatchesGoldenFileAndXsd(): void
    {
        $send = $this->vatUe(null);
        $document = $send->vatUe->build(VatUeTestTools::PERIOD);
        $xml = $document->xml;
        self::assertSame([], (new XsdValidator(XsdCatalog::default()))->validate($xml, VatUeBuilder::SCHEMA_UE));
        self::assertTrue($document->valid());

        $expected = VatUeTestTools::expected();
        self::assertSame($expected['positions'], $document->positions);
        self::assertSame([$expected['net_pln_sum'], $expected['p_uc_sum'], $expected['excluded_unidentified']], [$document->netPlnSum, $document->pUcSum, $document->excludedUnidentified]);
        self::assertSame([['P_Ua' => 'DE', 'P_Ub' => '999999999', 'P_Uc' => '3825'], ['P_Ua' => 'FR', 'P_Ub' => '99999999999', 'P_Uc' => '3393']], VatUeTestTools::groups($xml));
        self::assertStringNotContainsString('DaneAutoryzujace', $xml);
        self::assertStringNotContainsString('AT', implode('', array_column($document->positions, 'country')));
        self::assertStringStartsNotWith("\u{FEFF}", $xml);

        $golden = (string) file_get_contents(self::GOLDEN);
        EvidenceArtefacts::write('golden/vat-ue-golden.diff', self::diff($golden, $xml));
        self::assertSame($golden, $xml);
    }

    public function testMonthWithoutEuB2bSaleRefuses(): void
    {
        $send = $this->vatUe(null);
        $this->expectException(\App\Xml\JpkBuildRefused::class);
        $send->vatUe->build('2026-10-01');
    }

    private static function diff(string $expected, string $actual): string
    {
        $out = '';
        foreach (array_map(null, explode("\n", $expected), explode("\n", $actual)) as $index => [$left, $right]) {
            if ($left !== $right) {
                $out .= sprintf("@@ %d\n- %s\n+ %s\n", $index + 1, (string) $left, (string) $right);
            }
        }
        return $out;
    }
}
