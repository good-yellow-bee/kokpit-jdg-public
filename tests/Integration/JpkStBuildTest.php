<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Security\SecretStore;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\JpkTestTools;
use App\Xml\JpkBuildRefused;
use App\Xml\JpkStBuilder;
use App\Xml\XsdCatalog;
use Symfony\Component\Clock\MockClock;

/**
 * Kryterium `jpk-st-build` (F2.4, wiersz dopisany w F5.2 według D-37): JPK_ST(1) 1-2 z fikcyjnego wykazu
 * `fixed-assets-2026.json`, zgodny z XSD z `resources/xsd` (`LIBXML_NONET`), niezależnie od `jpk_st.enabled`.
 */
final class JpkStBuildTest extends DatabaseTestCase
{
    /** Schemat główny JPK_ST(1) i pliki bazowe, które importuje. */
    private const SCHEMAS = [JpkStBuilder::SCHEMA, 'StrukturyDanych_v10-0E.xsd', 'ElementarneTypyDanych_v10-0E.xsd', 'KodyKrajow_v10-0E.xsd', 'KodyUrzedowSkarbowych_v8-0E.xsd'];

    public function testFullYearRegisterMatchesXsdWithFlagOff(): void
    {
        $clock = new MockClock('2026-12-20T09:00:00Z');
        JpkTestTools::seed($this->connection, $clock);
        self::assertSame(6, JpkTestTools::seedFixedAssets($this->connection, $clock));
        self::assertFalse(JpkTestTools::settings($this->connection, $clock)->value('jpk_st.enabled'));

        $document = JpkTestTools::stBuilder($this->connection, $clock, $this->secrets())->build(2026);
        EvidenceArtefacts::write('jpk/jpk-st-build.xml', $document->xml);
        EvidenceArtefacts::write('cmd/jpk-st-build.txt', self::report($document->xsdErrors, $document->sha256(), $document->rows, $document->sum));
        self::assertSame([], $document->xsdErrors);
        self::assertSame(['JPK_ST', 'JPK_ST(1)', 4, '20500.00'], [$document->type, $document->schemaVersion, $document->rows, $document->sum]);
        self::assertTrue($document->continuous());

        $value = static fn(string $path): string => JpkTestTools::stValue($document->xml, $path);
        self::assertSame(['JPK_ST', 'JPK_ST (1)', '1-2', '1', '1'], [$value('/s:JPK/s:Naglowek/s:KodFormularza'), $value('/s:JPK/s:Naglowek/s:KodFormularza/@kodSystemowy'), $value('/s:JPK/s:Naglowek/s:KodFormularza/@wersjaSchemy'), $value('/s:JPK/s:Naglowek/s:WariantFormularza'), $value('/s:JPK/s:Naglowek/s:CelZlozenia')]);
        self::assertSame(['2026-01-01', '2026-12-31', '1471', '2026-12-20T09:00:00Z'], [$value('/s:JPK/s:Naglowek/s:DataOd'), $value('/s:JPK/s:Naglowek/s:DataDo'), $value('/s:JPK/s:Naglowek/s:KodUrzedu'), $value('/s:JPK/s:Naglowek/s:DataWytworzeniaJPK')]);
        $dataset = JpkTestTools::dataset();
        self::assertSame([$dataset['company_identity']['nip'], $dataset['auth_data_birth_date'], '1'], [$value('/s:JPK/s:Podmiot1/s:OsobaFizyczna/etd:NIP'), $value('/s:JPK/s:Podmiot1/s:OsobaFizyczna/etd:DataUrodzenia'), $value('/s:JPK/s:Podmiot1/s:ZnacznikST')]);

        $rows = JpkTestTools::stRows($document->xml);
        // Kolejność F_4; drukarka wykreślona w 2025 i serwer przyjęty w 2027 poza plikiem.
        self::assertSame(['1', '2', '3', '4'], array_column($rows, 'F_1'));
        self::assertSame(['Fikcyjny monitor (fictional)', 'Fikcyjny laptop (fictional)', 'Fikcyjna licencja oprogramowania (fictional)', 'Fikcyjny rower elektryczny (fictional)'], array_column($rows, 'F_7'));
        // Środek trwały w F_2, WNiP w F_3.
        self::assertSame(['2026-03-10', ''], [$rows[1]['F_2'], $rows[1]['F_3']]);
        self::assertSame(['', '2026-05-04', '3400.00', '1', ''], [$rows[2]['F_2'], $rows[2]['F_3'], $rows[2]['F_11'], $rows[2]['F_16'], $rows[2]['F_5']]);
        // Wykreślenie w roku: F_12–F_14 i węzeł KSeF z F_15.
        self::assertSame(['2026-09-15', 'S', 'FICTIONAL/2026/09/ST1', '1111111111-20260915-1A2B3C-4D5E6F-C3', '2'], [$rows[0]['F_12'], $rows[0]['F_13'], $rows[0]['F_14'], $rows[0]['F_15'], $rows[0]['F_16']]);
        self::assertSame(['F', '491', '30.00', '6500.00', ''], [$rows[1]['F_6'], $rows[1]['F_8'], $rows[1]['F_9'], $rows[1]['F_10'], $rows[1]['F_12']]);
        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $document->xml);
    }

    public function testUntilLimitsRowsAndKeepsWholeYearHeader(): void
    {
        $clock = new MockClock('2026-12-20T09:00:00Z');
        JpkTestTools::seed($this->connection, $clock);
        JpkTestTools::seedFixedAssets($this->connection, $clock);
        $document = JpkTestTools::stBuilder($this->connection, $clock, $this->secrets())->build(2026, '2026-11-30');
        self::assertSame([], $document->xsdErrors);
        $rows = JpkTestTools::stRows($document->xml);
        // Rower przyjęty 10.12.2026 wypada; wykreślenie monitora z 15.09.2026 zostaje.
        self::assertSame(['1', '2', '3'], array_column($rows, 'F_1'));
        self::assertSame('2026-09-15', $rows[0]['F_12']);
        self::assertSame([3, '11500.00', '2026-11-30'], [$document->rows, $document->sum, $document->until]);
        self::assertStringContainsString('<DataDo>2026-12-31</DataDo>', $document->xml);
    }

    public function testDisposalAfterUntilStaysOutOfFile(): void
    {
        $clock = new MockClock('2026-12-20T09:00:00Z');
        JpkTestTools::seed($this->connection, $clock);
        JpkTestTools::seedFixedAssets($this->connection, $clock);
        $document = JpkTestTools::stBuilder($this->connection, $clock, $this->secrets())->build(2026, '2026-08-31');
        self::assertSame([], $document->xsdErrors);
        $rows = JpkTestTools::stRows($document->xml);
        self::assertSame(['Fikcyjny monitor (fictional)', ''], [$rows[0]['F_7'], $rows[0]['F_12']]);
        self::assertSame('', $rows[0]['F_15']);
    }

    public function testEmptyRegisterIsValid(): void
    {
        $clock = new MockClock('2026-12-20T09:00:00Z');
        JpkTestTools::seed($this->connection, $clock);
        $document = JpkTestTools::stBuilder($this->connection, $clock, $this->secrets())->build(2026);
        self::assertSame([], $document->xsdErrors);
        self::assertSame([0, '0.00', []], [$document->rows, $document->sum, JpkTestTools::stRows($document->xml)]);
    }

    public function testDisposalWithoutDocumentNumberFailsXsd(): void
    {
        $clock = new MockClock('2026-12-20T09:00:00Z');
        JpkTestTools::seed($this->connection, $clock);
        JpkTestTools::seedFixedAssets($this->connection, $clock);
        $this->connection->executeStatement("UPDATE fixed_asset SET disposal_document_number=NULL WHERE disposal_date='2026-09-15'");
        $document = JpkTestTools::stBuilder($this->connection, $clock, $this->secrets())->build(2026);
        self::assertFalse($document->valid());
    }

    public function testRefusesUntilOutsideYear(): void
    {
        $clock = new MockClock('2026-12-20T09:00:00Z');
        JpkTestTools::seed($this->connection, $clock);
        $this->expectException(JpkBuildRefused::class);
        JpkTestTools::stBuilder($this->connection, $clock, $this->secrets())->build(2026, '2027-01-31');
    }

    /** @param list<string> $errors */
    private static function report(array $errors, string $sha256, int $rows, string $sum): string
    {
        $catalog = XsdCatalog::default();
        $manifest = array_column($catalog->entries(), 'sha256', 'file');
        $lines = [sprintf('Walidacja XSD (LIBXML_NONET, %s): %s', JpkStBuilder::SCHEMA, $errors === [] ? 'zgodny' : 'niezgodny')];
        foreach ($errors as $error) {
            $lines[] = '- ' . $error;
        }
        $lines[] = sprintf('Wiersze EWP: %d; suma F_10: %s; sha256 pliku: %s', $rows, $sum, $sha256);
        foreach (self::SCHEMAS as $file) {
            $actual = hash_file('sha256', $catalog->path($file));
            $lines[] = sprintf('XSD %s: sha256 %s, manifest %s, %s', $file, $actual, $manifest[$file], $actual === $manifest[$file] ? 'zgodne' : 'NIEZGODNE');
        }
        return implode("\n", $lines) . "\n";
    }

    private function secrets(): SecretStore
    {
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        return $secrets;
    }
}
