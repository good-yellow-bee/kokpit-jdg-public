<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\Pit28TestTools;
use App\Xml\JpkBuildRefused;
use App\Xml\Pit28Builder;
use App\Xml\XsdCatalog;
use App\Xml\XsdValidator;
use Symfony\Component\Uid\UuidV7;

/**
 * PIT-28(27) za 2025 z fikcyjnego roku (D-96): zgodny z XSD offline i bajt w bajt ze złotym plikiem. Przychód per
 * stawka w D.1 i D.5, udział D.7, składki społeczne w E.1 i F.1, 50% zdrowotnej w E.4 i F.2, podstawa per stawka
 * w pełnych złotych (K), ryczałt per stawka (L), należny ryczałt w pełnych złotych (N), wpłacone zaliczki i ryczałt
 * do zapłaty (P), ryczałt miesięcy z ewidencji (Q); bez `DaneAutoryzujace`. Dane fikcyjne.
 */
final class Pit28GoldenTest extends Pit28TestCase
{
    private const GOLDEN = __DIR__ . '/../Fixtures/Jpk/golden/pit-28-golden.xml';

    public function testPit28MatchesGoldenFileAndXsd(): void
    {
        $send = $this->pit28();
        $document = $send->pit28->build(Pit28TestTools::YEAR);
        $xml = $document->xml;
        self::assertSame([], (new XsdValidator(XsdCatalog::default()))->validate($xml, Pit28Builder::SCHEMA_27));
        self::assertTrue($document->valid());
        self::assertSame([Pit28Builder::VARIANT_27, Pit28Builder::SCHEMA_27], [$document->variant, $document->schema]);
        self::assertStringNotContainsString('DaneAutoryzujace', $xml);
        self::assertStringStartsNotWith("\u{FEFF}", $xml);

        $fields = self::fields($xml);
        // D: 12% 180 000,00 i 8,5% 4 801,00; udziały 97,40% i 2,60%.
        self::assertSame(['180000.00', '4801.00', '184801.00', '97.40', '2.60'], [$fields['P_57'], $fields['P_55'], $fields['P_63'], $fields['P_81'], $fields['P_79']]);
        // E: społeczne 12 × 1 600,32 (bez pozostałych 120,00), 50% zdrowotnej 12 × 830,58.
        self::assertSame(['19203.84', '165597.16', '19203.84', '4983.48', '4983.48'], [$fields['P_98'], $fields['P_100'], $fields['P_104'], $fields['P_105'], $fields['P_107']]);
        // F.1: 19 203,84 × 97,40% i × 2,60%; F.2: 4 983,48 × 180 000 / 184 801 i × 4 801 / 184 801.
        self::assertSame(['18704.54', '499.30', '4854.01', '129.47'], [$fields['P_114'], $fields['P_112'], $fields['P_125'], $fields['P_123']]);
        // G, I i K: 180 000 − 18 704,54 − 4 854,01 = 156 441,45 → 156 441; 4 801 − 499,30 − 129,47 = 4 172,23 → 4 172.
        self::assertSame(['156441.45', '4172.23', '160613.68', '156441', '4172'], [$fields['P_137'], $fields['P_135'], $fields['P_183'], $fields['P_202'], $fields['P_200']]);
        // L i N: 12% × 156 441 = 18 772,92; 8,5% × 4 172 = 354,62; razem 19 127,54 → 19 128.
        self::assertSame(['18772.92', '354.62', '19127.54', '19128'], [$fields['P_217'], $fields['P_215'], $fields['P_224'], $fields['P_232']]);
        $q = array_map(static fn(int $position): int => (int) $fields['P_' . $position], range(239, 250));
        // P: wpłaty za styczeń–listopad równe ryczałtowi tych miesięcy z ewidencji; do zapłaty różnica do należnego.
        self::assertSame((string) array_sum(array_slice($q, 0, 11)), $fields['P_236']);
        self::assertSame((string) (19128 - array_sum(array_slice($q, 0, 11))), $fields['P_237']);
        self::assertArrayNotHasKey('P_238', $fields);
        self::assertArrayNotHasKey('P_267', $fields);
        self::assertSame(
            ['revenue_total' => Pit28TestTools::REVENUE_TOTAL, 'advances_paid' => $fields['P_236'] . '.00', 'ryczalt_due' => '19128', 'to_pay' => $fields['P_237'], 'overpaid' => '0'],
            array_intersect_key($document->totals, array_flip(['ryczalt_due', 'to_pay', 'overpaid', 'advances_paid', 'revenue_total'])),
        );

        $golden = (string) file_get_contents(self::GOLDEN);
        EvidenceArtefacts::write('golden/pit-28-golden.diff', self::diff($golden, $xml));
        self::assertSame($golden, $xml);
    }

    public function testQuarterlyAdvancesFillQuarterPositionsAndPosition267(): void
    {
        $send = $this->pit28(quarterly: true);
        $document = $send->pit28->build(Pit28TestTools::YEAR);
        self::assertTrue($document->valid(), implode('; ', $document->xsdErrors));
        $fields = self::fields($document->xml);
        self::assertSame('1', $fields['P_267']);
        foreach ([239, 240, 242, 243, 245, 246, 248, 249] as $month) {
            self::assertArrayNotHasKey('P_' . $month, $fields);
        }
        $quarters = array_map(static fn(int $position): int => (int) $fields['P_' . $position], [241, 244, 247, 250]);
        self::assertSame((string) array_sum(array_slice($quarters, 0, 3)), $fields['P_236']);
        self::assertSame('19128', $fields['P_232']);
    }

    public function testTwoPercentRateRefuses(): void
    {
        $send = $this->pit28();
        $this->db()->insert('revenue_entry', ['id' => (string) UuidV7::generate($send->clock->now()), 'environment' => 'test', 'year' => Pit28TestTools::YEAR, 'lp' => 100,
            'entry_date' => '2025-12-20', 'revenue_date' => '2025-12-20', 'document_number' => 'FV/2025/12/100 (fictional)', 'amount_pln' => '100.00', 'rate_pct' => '2.00', 'source' => 'import',
            'kind' => 'regular', 'recorded_at' => $send->clock->now()->format('Y-m-d\\TH:i:s.uP')]);
        $this->expectException(JpkBuildRefused::class);
        $this->expectExceptionMessage('Stawka 2.00% nie ma pozycji w części D.1 PIT-28(27)');
        $send->pit28->build(Pit28TestTools::YEAR);
    }

    public function testMissingResidenceAddressRefuses(): void
    {
        $send = $this->pit28(residence: false);
        $this->expectExceptionObject(new JpkBuildRefused('Brak taxpayer.residence_address w ustawieniach: PIT-28 wymaga adresu zamieszkania (część B).'));
        $send->pit28->build(Pit28TestTools::YEAR);
    }

    public function testYearWithoutVariantRefuses(): void
    {
        $send = $this->pit28();
        $this->expectExceptionObject(new JpkBuildRefused(Pit28Builder::variantMissing(2026)));
        $send->pit28->build(2026);
    }

    /** @return array<string,string> pozycje `PozycjeSzczegolowe` */
    private static function fields(string $xml): array
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml, LIBXML_NONET));
        $fields = [];
        foreach ($dom->getElementsByTagNameNS(Pit28Builder::TNS_27, 'PozycjeSzczegolowe')->item(0)->childNodes ?? [] as $node) {
            if ($node instanceof \DOMElement) {
                $fields[$node->localName] = $node->textContent;
            }
        }
        return $fields;
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
