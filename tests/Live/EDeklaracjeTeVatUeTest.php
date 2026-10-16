<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Tests\Support\VatUeTestTools;
use PHPUnit\Framework\Attributes\Group;

/** Kryterium `edeklaracje-te-vat-ue` (F5.6, live): VAT-UE(5) z danymi autoryzującymi przyjęty z UPO, bez kodu 418. */
#[Group('live')]
final class EDeklaracjeTeVatUeTest extends EDeklaracjeLiveTestCase
{
    public function testVatUeAcceptedWithUpoOnTestGateway(): void
    {
        $send = $this->liveVatUe();
        $approvalId = $send->request->vatUe(VatUeTestTools::PERIOD);
        $filing = $this->sendLive($send, $approvalId, 'VAT_UE', 0, 'live', 'edeklaracje-te-vat-ue');
        self::assertSame(['accepted', 200], [$filing['status'], $filing['status_code']]);
        $refs = $send->refs($approvalId);
        self::assertStringContainsString('<Przyjeto>true</Przyjeto>', $send->files->read($refs['upo_file_id']));
    }
}
