<?php

declare(strict_types=1);

namespace App\Tests\Live;

use PHPUnit\Framework\Attributes\Group;

/**
 * Kryterium `jpk-te-duplicate` (F5.3, live): te same bajty JPK_EWP(4) drugi raz, nowym zatwierdzeniem po stanie
 * końcowym pierwszego. Po przyjętym oryginale: kod duplikatu (170, 407 albo 411) i jedno złożenie. Po odrzuconym
 * oryginale (419, D-69) MF nie traktuje pliku jako duplikatu: drugi numer referencyjny i ten sam kod 419.
 */
#[Group('live')]
final class JpkTeDuplicateTest extends JpkLiveTestCase
{
    public function testSameBytesAgain(): void
    {
        $send = $this->liveSend();
        $first = $send->request->jpkEwp(2026);
        $accepted = $this->assertTerminalOnTestGateway($send, $first, $this->sendLive($send, $first, 'JPK_EWP', 'live-ewp-first', 'jpk-te-duplicate-first'));
        $firstReference = $send->filing()['reference_number'];
        $second = $send->request->jpkEwp(2026);
        self::assertNotSame($first, $second);
        self::assertSame($send->approval($first)['payload_sha256'], $send->approval($second)['payload_sha256']);
        $this->sendLive($send, $second, 'JPK_EWP', 'live-ewp-duplicate', 'jpk-te-duplicate');
        $row = $send->approval($second);
        if ($accepted) {
            self::assertContains($row['result_code'], ['170', '407', '411']);
            self::assertSame(['accepted', $firstReference], [$row['status'], $send->refs($second)['original_reference_number'] ?? null]);
            return;
        }
        self::assertSame(['rejected', '419'], [$row['status'], $row['result_code']]);
        self::assertNotSame($firstReference, $send->filing()['reference_number']);
    }
}
