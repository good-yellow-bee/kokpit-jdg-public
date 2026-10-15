<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Tests\Support\VatUeTestTools;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kryterium `edeklaracje-te-vat-uek` (F5.6, live): po przyjętym VAT-UE i późnej KOR VAT-UEK(5) z danymi
 * autoryzującymi przyjęty z UPO, bez kodów 410 i 418 (D-27 bez zmian).
 */
#[Group('live')]
final class EDeklaracjeTeVatUekTest extends EDeklaracjeLiveTestCase
{
    public function testVatUekAcceptedWithUpoOnTestGateway(): void
    {
        $send = $this->liveVatUe();
        $original = $this->sendLive($send, $send->request->vatUe(VatUeTestTools::PERIOD), 'VAT_UE', 0, 'live', 'edeklaracje-te-vat-uek-original');
        self::assertSame('accepted', $original['status']);
        $this->lateKor($send);
        self::assertSame('vat_uek', $send->request->vatUeRoute(VatUeTestTools::PERIOD));
        $filing = $this->sendLive($send, $send->request->vatUe(VatUeTestTools::PERIOD), 'VAT_UEK', 1, 'live-uek', 'edeklaracje-te-vat-uek');
        self::assertSame(['accepted', 200], [$filing['status'], $filing['status_code']]);
        self::assertSame('superseded', $send->filing('VAT_UE', VatUeTestTools::PERIOD)['status']);
    }
}
