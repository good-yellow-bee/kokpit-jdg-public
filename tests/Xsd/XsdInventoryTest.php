<?php

declare(strict_types=1);

namespace App\Tests\Xsd;

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
