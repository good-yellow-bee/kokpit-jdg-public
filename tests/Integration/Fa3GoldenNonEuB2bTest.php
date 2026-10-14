<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\Fa3TestTools;

/** `fa3-golden-non-eu-b2b`: 1 000,00 USD, dowód `manual_evidence` (F3.2), usługa okresowa (`OkresFa`). */
final class Fa3GoldenNonEuB2bTest extends Fa3BuilderTestCase
{
    public function testNonEuB2bMatchesGoldenFile(): void
    {
        $payload = $this->build('fa3-non-eu-b2b');
        self::assertSame(['np I'], self::values($payload, '//f:FaWiersz/f:P_12'));
        self::assertSame(['1000.00'], self::values($payload, '/f:Faktura/f:Fa/f:P_13_8'));
        self::assertSame(['US'], self::values($payload, '/f:Faktura/f:Podmiot2/f:DaneIdentyfikacyjne/f:KodKraju'));
        self::assertSame(['99-9999999'], self::values($payload, '/f:Faktura/f:Podmiot2/f:DaneIdentyfikacyjne/f:NrID'));
        // Założenie F3.1 do przeglądu doradcy (Q-29, docs/modules/ksef.md): P_18 = 1.
        self::assertSame(['1'], self::values($payload, '//f:Adnotacje/f:P_18'));
        self::assertSame(['2026-10-01', '2026-10-31'], self::values($payload, '/f:Faktura/f:Fa/f:OkresFa/*'));
        self::assertSame('2026-10-31', $payload->taxPointDate);
        self::assertSame('3900.00', $payload->netPln);
        Fa3TestTools::assertGolden('fa3-golden-non-eu-b2b', ['fa3-non-eu-b2b' => $payload->xml]);
    }
}
