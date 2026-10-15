<?php

declare(strict_types=1);

namespace App\Tests\Xsd;

use App\Connector\Jpk\AuthDataDocument;
use App\Connector\Jpk\JpkInitUpload;
use App\Invoice\Fa3Reader;
use App\Ledger\JpkEwpSchema;
use App\Xml\XsdCatalog;
use App\Xml\XsdValidator;
use PHPUnit\Framework\TestCase;

final class XsdInventoryTest extends TestCase
{
    public function testEveryManifestEntryMatchesItsChecksum(): void
    {
        self::assertSame([], XsdCatalog::default()->mismatches());
    }
    public function testEveryVendoredFileIsInTheManifestOnce(): void
    {
        $files = array_column(XsdCatalog::default()->entries(), 'file');
        self::assertSame(array_values(array_unique($files)), $files);
        $onDisk = array_map(basename(...), glob(dirname(__DIR__, 2) . '/resources/xsd/*.xsd') ?: []);
        sort($onDisk);
        sort($files);
        self::assertSame($onDisk, $files);
    }
    /** @return iterable<string,array{string}> */
    public static function roots(): iterable
    {
        // Schematy główne dokumentów urzędowych i metadanych (D-63); importy rozwiązuje loader z manifestu.
        foreach ([Fa3Reader::SCHEMA, JpkEwpSchema::SCHEMA, 'Schemat_JPK_V7M(3)_v1-0E.xsd', 'Schemat_JPK_ST(1)_v1-2.xsd', 'VAT-UE(5)_v2-0E.xsd', 'VAT-UEK(5)_v2-1E.xsd', AuthDataDocument::SCHEMA, JpkInitUpload::SCHEMA] as $root) {
            yield $root => [$root];
        }
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('roots')]
    public function testRootSchemaCompilesOffline(string $root): void
    {
        // Fikcyjny element spoza schematu: jedyny błąd to brak deklaracji korzenia, więc schemat z importami skompilował się bez sieci.
        $errors = (new XsdValidator(XsdCatalog::default()))->validate('<fictional xmlns="urn:fictional"/>', $root);
        self::assertCount(1, $errors, implode('; ', $errors));
        self::assertStringContainsString('No matching global declaration available for the validation root', $errors[0]);
        self::assertNull(libxml_get_external_entity_loader());
    }
    public function testEveryImportResolvesToOneVendoredFileByFullAddress(): void
    {
        $catalog = XsdCatalog::default();
        $locations = $catalog->locations();
        $used = [];
        foreach ($catalog->entries() as $entry) {
            $schema = self::schema($catalog->path($entry['file']));
            foreach (['import', 'include', 'redefine'] as $kind) {
                foreach ($schema->getElementsByTagNameNS('http://www.w3.org/2001/XMLSchema', $kind) as $node) {
                    $location = $node->getAttribute('schemaLocation');
                    self::assertArrayHasKey($location, $locations, $entry['file'] . ' → ' . $location);
                    // Plik z mapy deklaruje przestrzeń nazw, której oczekuje import (albo include: przestrzeń schematu nadrzędnego).
                    $expected = $kind === 'import' ? $node->getAttribute('namespace') : $schema->documentElement?->getAttribute('targetNamespace');
                    self::assertSame($expected, self::schema($locations[$location])->documentElement?->getAttribute('targetNamespace'), $location);
                    $used[] = $location;
                }
            }
        }
        // Każdy adres z manifestu jest faktycznie importowany.
        foreach ($catalog->entries() as $entry) {
            foreach ($entry['locations'] ?? [] as $location) {
                self::assertContains($location, $used, $entry['file']);
            }
        }
    }
    public function testJpkEwpSchemaIsVendoredForF21(): void
    {
        // F2.1: import XML JPK_EWP(4) i słowniki K_6, K_9 wymagają schematu z importami z crd.gov.pl.
        $catalog = XsdCatalog::default();
        foreach ([JpkEwpSchema::SCHEMA, JpkEwpSchema::COUNTRIES] as $file) {
            self::assertTrue($catalog->has($file), 'Brak ' . $file . ' w resources/xsd/checksums.json.');
        }
    }
    public function testFixtureValidatesOfflineAndLoaderIsRestored(): void
    {
        $xml = file_get_contents(dirname(__DIR__) . '/Fixtures/Jpk/ledger-2026-jpk-ewp.xml');
        self::assertIsString($xml);
        self::assertSame([], (new XsdValidator(XsdCatalog::default()))->validate($xml, JpkEwpSchema::SCHEMA));
        // Loader działa w całym procesie, więc po walidacji wraca domyślny.
        self::assertNull(libxml_get_external_entity_loader());
    }
    public function testFa3GoldenFilesValidateOffline(): void
    {
        // F3.1: złote pliki FA(3) w tests/Fixtures/Ksef/golden walidują się bez sieci (FA(3) 1-0E z importami crd.gov.pl).
        $files = glob(dirname(__DIR__) . '/Fixtures/Ksef/golden/fa3-*.xml') ?: [];
        self::assertNotSame([], $files);
        $validator = new XsdValidator(XsdCatalog::default());
        foreach ($files as $file) {
            $xml = file_get_contents($file);
            self::assertIsString($xml);
            self::assertSame([], $validator->validate($xml, Fa3Reader::SCHEMA), basename($file));
        }
        self::assertNull(libxml_get_external_entity_loader());
    }
    public function testAddressMappedToTwoFilesIsRejected(): void
    {
        $directory = sys_get_temp_dir() . '/kokpit-xsd-' . bin2hex(random_bytes(4));
        mkdir($directory);
        $entries = [];
        foreach (['fictional-a.xsd', 'fictional-b.xsd'] as $file) {
            $schema = '<xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema" targetNamespace="urn:fictional:' . $file . '"/>';
            file_put_contents($directory . '/' . $file, $schema);
            $entries[] = ['file' => $file,'source' => 'fictional','fetched_on' => '2026-10-15','sha256' => hash('sha256', $schema),'locations' => ['http://dummy.invalid/Fictional_v1-0E.xsd']];
        }
        file_put_contents($directory . '/checksums.json', json_encode($entries, JSON_THROW_ON_ERROR));
        $this->expectExceptionMessage('wskazuje w manifeście XSD dwa pliki');
        (new XsdCatalog($directory))->locations();
    }
    private static function schema(string $path): \DOMDocument
    {
        $document = new \DOMDocument();
        $bytes = file_get_contents($path);
        self::assertIsString($bytes);
        self::assertTrue($document->loadXML($bytes, LIBXML_NONET), $path);
        return $document;
    }
    public function testUnknownRemoteSchemaIsNeverFetched(): void
    {
        $directory = sys_get_temp_dir() . '/kokpit-xsd-' . bin2hex(random_bytes(4));
        mkdir($directory);
        $schema = '<xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema" targetNamespace="urn:fictional" xmlns:r="urn:remote"><xsd:import namespace="urn:remote" schemaLocation="http://dummy.invalid/remote.xsd"/><xsd:element name="a" type="r:T"/></xsd:schema>';
        file_put_contents($directory . '/fictional.xsd', $schema);
        file_put_contents($directory . '/checksums.json', json_encode([['file' => 'fictional.xsd','source' => 'fictional','fetched_on' => '2026-10-10','sha256' => hash('sha256', $schema)]], JSON_THROW_ON_ERROR));
        $errors = (new XsdValidator(new XsdCatalog($directory)))->validate('<a xmlns="urn:fictional"/>', 'fictional.xsd');
        self::assertNotSame([], $errors);
        self::assertNull(libxml_get_external_entity_loader());
    }
}
