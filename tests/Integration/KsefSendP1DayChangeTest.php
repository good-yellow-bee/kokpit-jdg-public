<?php

declare(strict_types=1);

namespace App\Tests\Integration;

/**
 * Kryterium `ksef-send-p1-day-change` (F3.3, D-16): zatwierdzenie z P_1 16.11.2026 i worker przy `MockClock`
 * 17.11.2026 00:05 w Warszawie. Wysyłka nie rusza: `expired`, faktura `ready`, push `connector_failure`, zero wywołań.
 */
final class KsefSendP1DayChangeTest extends KsefSendTestCase
{
    public function testWorkerAfterMidnightExpiresWithoutCalls(): void
    {
        $send = $this->send();
        $id = $send->approved();
        $approvalId = $send->sales->approvalId($id);
        self::assertSame('2026-11-16', $send->invoice($id)['issue_date']);

        // 17.11.2026 00:05 w Europe/Warsaw (UTC+1).
        $send->sales->clock->modify('2026-11-16T23:05:00Z');
        self::assertNull($send->handler->dispatch($approvalId));

        self::assertSame('expired', $send->approval($approvalId)['status']);
        self::assertSame('ready', $send->invoice($id)['status']);
        self::assertSame(0, $send->calls());
        self::assertSame(0, $send->calls(null, 'latarnia'));
        self::assertSame(1, $send->sales->count("SELECT count(*) FROM notification WHERE category='connector_failure' AND approval_id=?", [$approvalId]));
    }
}
