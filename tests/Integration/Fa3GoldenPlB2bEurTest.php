<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\Fa3TestTools;

/** `fa3-golden-pl-b2b-eur`: 1 000,00 EUR netto, 23%, fikcyjny kurs VAT 4,2500 z tabeli sprzed obowiązku (art. 31a ust. 1). */
final class Fa3GoldenPlB2bEurTest extends Fa3BuilderTestCase
{
    public function testPlB2bInEuroHasPolishVatInZloty(): void
    {
        $payload = $this->build('fa3-pl-b2b-eur');
        self::assertSame(['230.00'], self::values($payload, '/f:Faktura/f:Fa/f:P_14_1'));
        self::assertSame(['977.50'], self::values($payload, '/f:Faktura/f:Fa/f:P_14_1W'));
        $rate = $payload->fxVatRate;
        self::assertNotNull($rate);
        $row = $this->db()->fetchAssociative("SELECT id,table_no,effective_date,mid FROM fx_rate WHERE source='nbp' AND currency='EUR' AND effective_date='2026-11-12'");
        self::assertIsArray($row);
        self::assertSame($row['id'], $rate->id);
        self::assertSame($row['mid'], $rate->mid);
        self::assertSame([$rate->mid], self::values($payload, '//f:FaWiersz/f:KursWaluty'));
        self::assertIsString($row['table_no']);
        self::assertStringContainsString($row['table_no'], implode(' ', self::values($payload, '//f:DodatkowyOpis/f:Wartosc')));
        self::assertSame('977.50', $payload->vatPln);
        Fa3TestTools::assertGolden('fa3-golden-pl-b2b-eur', ['fa3-pl-b2b-eur' => $payload->xml]);
    }
}
