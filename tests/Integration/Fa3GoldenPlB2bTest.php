<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\Fa3TestTools;

/** `fa3-golden-pl-b2b`: dwie pozycje po 333,33 zł netto, 23%; VAT od sumy netto stawki (art. 106e ust. 11). */
final class Fa3GoldenPlB2bTest extends Fa3BuilderTestCase
{
    public function testPlB2bRoundsVatFromNetSum(): void
    {
        $payload = $this->build('fa3-pl-b2b');
        self::assertSame(['666.66'], self::values($payload, '/f:Faktura/f:Fa/f:P_13_1'));
        self::assertSame(['153.33'], self::values($payload, '/f:Faktura/f:Fa/f:P_14_1'));
        self::assertSame(['819.99'], self::values($payload, '/f:Faktura/f:Fa/f:P_15'));
        self::assertSame(['2'], self::values($payload, '//f:Adnotacje/f:P_18'));
        self::assertSame([Fa3TestTools::CLIENT_NIP], self::values($payload, '/f:Faktura/f:Podmiot2/f:DaneIdentyfikacyjne/f:NIP'));
        self::assertSame(['PLN'], self::values($payload, '/f:Faktura/f:Fa/f:KodWaluty'));
        // Data usługi równa P_1: P_6 pomija się (art. 106e ust. 1 pkt 6).
        self::assertSame([], self::values($payload, '/f:Faktura/f:Fa/f:P_6'));
        self::assertSame([], self::values($payload, '//f:P_14_1W'));
        Fa3TestTools::assertGolden('fa3-golden-pl-b2b', ['fa3-pl-b2b' => $payload->xml]);
    }
}
