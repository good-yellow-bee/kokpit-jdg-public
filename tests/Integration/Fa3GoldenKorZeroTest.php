<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\Fa3TestTools;
use App\Xml\Fa3Invoice;
use App\Xml\Fa3Scenario;

/**
 * `fa3-golden-kor-zero`: błędny NIP nabywcy → KOR do zera i nowa faktura; oryginał sprzed przejścia → KOR z `NrKSeFN`.
 * `replaces_invoice_id`, `corrects_invoice_id` i stany oryginału to wiersze `invoice` z F3.2; tu tylko XML.
 */
final class Fa3GoldenKorZeroTest extends Fa3BuilderTestCase
{
    public function testCorrectionToZeroAndCorrectionOfInvoiceOutsideKsef(): void
    {
        $zero = $this->build('fa3-kor-zero');
        self::assertSame(['-1000.00'], self::values($zero, '/f:Faktura/f:Fa/f:P_13_1'));
        self::assertSame(['-230.00'], self::values($zero, '/f:Faktura/f:Fa/f:P_14_1'));
        self::assertSame(['-1230.00'], self::values($zero, '/f:Faktura/f:Fa/f:P_15'));
        self::assertSame([Fa3TestTools::WRONG_NIP], self::values($zero, '/f:Faktura/f:Podmiot2/f:DaneIdentyfikacyjne/f:NIP'));
        self::assertSame(['1'], self::values($zero, '//f:DaneFaKorygowanej/f:NrKSeF'));
        // Nowa faktura do właściwego nabywcy: zwykła `pl_b2b`, zgodna z XSD.
        $replacement = $this->builder()->build(new Fa3Invoice('VAT', 'FV/2026/11/011', Fa3Scenario::PlB2b, 'PLN', Fa3TestTools::plBuyer(), [Fa3TestTools::line(1, '1000.00', '23')], '2026-11-05'));
        self::assertSame([Fa3TestTools::CLIENT_NIP], self::values($replacement, '/f:Faktura/f:Podmiot2/f:DaneIdentyfikacyjne/f:NIP'));
        self::assertSame(['1230.00'], self::values($replacement, '/f:Faktura/f:Fa/f:P_15'));
        $outside = $this->build('fa3-kor-nrksefn');
        self::assertSame(['1'], self::values($outside, '//f:DaneFaKorygowanej/f:NrKSeFN'));
        self::assertSame([], self::values($outside, '//f:NrKSeFFaKorygowanej | //f:DaneFaKorygowanej/f:NrKSeF'));
        self::assertSame(['-100.00'], self::values($outside, '/f:Faktura/f:Fa/f:P_13_1'));
        Fa3TestTools::assertGolden('fa3-golden-kor-zero', ['fa3-kor-zero' => $zero->xml,'fa3-kor-nrksefn' => $outside->xml]);
    }
}
