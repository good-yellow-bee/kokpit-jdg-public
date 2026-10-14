<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\Fa3TestTools;
use App\Xml\Fa3Correction;
use App\Xml\Fa3Invoice;
use App\Xml\Fa3Scenario;

/** `fa3-golden-kor`: KOR kwotowa −200,00 EUR do przyjętej faktury `eu_b2b`, bez i z `PrzyczynaKorekty` i `TypKorekty`. */
final class Fa3GoldenKorTest extends Fa3BuilderTestCase
{
    public function testAmountCorrectionMatchesGoldenFilesInBothVariants(): void
    {
        // Źródło NBP bez kursów: KOR nie może sięgnąć po nowy kurs, bierze kurs faktury pierwotnej.
        $builder = Fa3TestTools::builder($this->db(), $this->clock, []);
        $plain = $builder->build(Fa3TestTools::invoice('fa3-kor'));
        $reason = $builder->build(Fa3TestTools::invoice('fa3-kor-reason'));
        foreach ([$plain, $reason] as $payload) {
            self::assertSame(['KOR'], self::values($payload, '/f:Faktura/f:Fa/f:RodzajFaktury'));
            self::assertSame(['1'], self::values($payload, '//f:DaneFaKorygowanej/f:NrKSeF'));
            self::assertSame([Fa3TestTools::OWN_NIP . '-20261102-0A1B2C-3D4E5F-A1'], self::values($payload, '//f:DaneFaKorygowanej/f:NrKSeFFaKorygowanej'));
            self::assertSame(['-200.00'], self::values($payload, '/f:Faktura/f:Fa/f:P_13_9'));
            self::assertSame(['-200.00'], self::values($payload, '/f:Faktura/f:Fa/f:P_15'));
            // Kurs faktury pierwotnej (art. 31b), bez nowego kursu NBP.
            self::assertEquals(Fa3TestTools::originalRate(), $payload->fxVatRate);
            self::assertSame('-860.00', $payload->netPln);
        }
        // Pusty wybór pomija element.
        self::assertSame([], self::values($plain, '//f:PrzyczynaKorekty | //f:TypKorekty'));
        self::assertSame(['Fikcyjny rabat po odbiorze usługi'], self::values($reason, '//f:PrzyczynaKorekty'));
        self::assertSame(['2'], self::values($reason, '//f:TypKorekty'));
        Fa3TestTools::assertGolden('fa3-golden-kor', ['fa3-kor' => $plain->xml,'fa3-kor-reason' => $reason->xml]);
    }

    public function testCorrectionInForeignCurrencyUsesOriginalRateForPolishVat(): void
    {
        $original = Fa3TestTools::originalRate();
        $invoice = new Fa3Invoice('KOR', 'KOR/2026/11/009', Fa3Scenario::PlB2b, 'EUR', Fa3TestTools::plBuyer(), [Fa3TestTools::line(1, '1000.00', '23', before: true), Fa3TestTools::line(1, '800.00', '23')], '2026-10-30', correction: new Fa3Correction('FV/2026/10/009', '2026-10-30', Fa3TestTools::OWN_NIP . '-20261030-0A1B2C-3D4E5F-A2', null, null, $original));
        // Bez kursów w źródle NBP: generator nie może sięgnąć po nowy kurs.
        $payload = Fa3TestTools::builder($this->db(), $this->clock, [])->build($invoice);
        self::assertSame(['-46.00'], self::values($payload, '/f:Faktura/f:Fa/f:P_14_1'));
        self::assertSame(['-197.80'], self::values($payload, '/f:Faktura/f:Fa/f:P_14_1W'));
        self::assertEquals($original, $payload->fxVatRate);
    }

    public function testCorrectionInForeignCurrencyWithoutOriginalRateIsRefused(): void
    {
        $invoice = new Fa3Invoice('KOR', 'KOR/2026/11/010', Fa3Scenario::EuB2b, 'EUR', Fa3TestTools::euBuyer(), [Fa3TestTools::line(1, '-200.00', 'np II', price: '-200.00')], '2026-11-02', correction: new Fa3Correction('FV/2026/11/000', '2026-11-02', Fa3TestTools::OWN_NIP . '-20261102-0A1B2C-3D4E5F-A1'));
        $this->expectExceptionMessage('art. 31b');
        $this->builder()->build($invoice);
    }
}
