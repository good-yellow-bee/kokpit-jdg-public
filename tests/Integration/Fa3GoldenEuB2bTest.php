<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Invoice\Fa3Reader;
use App\Tests\Support\Fa3TestTools;
use App\Xml\XsdCatalog;
use App\Xml\XsdValidator;

/** `fa3-golden-eu-b2b`: 1 000,00 EUR, VIES `valid` (weryfikacja to F3.2), `MockClock` 16.11.2026. */
final class Fa3GoldenEuB2bTest extends Fa3BuilderTestCase
{
    public function testEuB2bMatchesGoldenFileAndSchemaOffline(): void
    {
        $payload = $this->build('fa3-eu-b2b');
        self::assertSame(['np II'], self::values($payload, '//f:FaWiersz/f:P_12'));
        self::assertSame(['1000.00'], self::values($payload, '/f:Faktura/f:Fa/f:P_13_9'));
        self::assertSame(['1'], self::values($payload, '//f:Adnotacje/f:P_18'));
        self::assertSame(['EUR'], self::values($payload, '/f:Faktura/f:Fa/f:KodWaluty'));
        self::assertSame(['DE'], self::values($payload, '/f:Faktura/f:Podmiot2/f:DaneIdentyfikacyjne/f:KodUE'));
        self::assertSame(['999999999'], self::values($payload, '/f:Faktura/f:Podmiot2/f:DaneIdentyfikacyjne/f:NrVatUE'));
        self::assertSame([], self::values($payload, '/f:Faktura/f:Fa/*[starts-with(local-name(), "P_14_")]'));
        self::assertSame([], self::values($payload, '//f:KursWaluty'));
        self::assertSame([], self::values($payload, '//f:BrakID'));
        self::assertSame(['PL'], self::values($payload, '/f:Faktura/f:Podmiot1/f:PrefiksPodatnika'));
        // Kurs VAT (tabela sprzed obowiązku 13.11) tylko do net_pln; w XML go nie ma, bo nie ma polskiego VAT.
        self::assertSame('4250.00', $payload->netPln);
        $errors = (new XsdValidator(XsdCatalog::default()))->validate($payload->xml, Fa3Reader::SCHEMA);
        self::assertSame([], $errors);
        self::assertNull(libxml_get_external_entity_loader());
        Fa3TestTools::assertGolden('fa3-golden-eu-b2b', ['fa3-eu-b2b' => $payload->xml]);
        $manifest = array_column(XsdCatalog::default()->entries(), 'sha256', 'file');
        foreach (['schemat_FA(3)_v1-0E.xsd','StrukturyDanych_v10-0E.xsd','ElementarneTypyDanych_v10-0E.xsd','KodyKrajow_v10-0E.xsd'] as $file) {
            self::assertSame($manifest[$file] ?? null, hash_file('sha256', dirname(__DIR__, 2) . '/resources/xsd/' . $file));
        }
    }
}
