<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\JpkTestTools;
use App\Tests\Support\JpkV7mTestTools;

/**
 * Kryterium `jpk-te-fixtures` (F5.3, D-69): nagrania JPK_EWP, JPK_ST i JPK_V7M z test-e-dokumenty (`live-*-upload`: `InitUploadSigned` 200 z
 * adresem magazynu `taxdocumentstorageNNtst`, Put Blob 201, `FinishUpload` 200; `live-*-status`: `Status` 419)
 * odtworzone przez bramkę zatwierdzeń i `JpkWriter`. Fikcyjne dane autoryzujące kończą się 419 po pełnym przyjęciu
 * pliku: `rejected` bez UPO i bez ponowień. Dane fikcyjne.
 */
final class JpkLiveRecordingTest extends JpkV7mTestCase
{
    private const EWP_REFERENCE = '3fa9876c03e2d608000000be1c1c081b';
    private const ST_REFERENCE = '3fa9cac70086a36f000000be685f33b1';
    private const V7M_REFERENCE = '3fabffc9014f0ce4000000be1ee6e0bd';

    public function testJpkEwpRecordingsReplayToRejected419(): void
    {
        $send = $this->send();
        $approvalId = $send->approved();
        $send->fake->load('live-ewp-upload', 'live-ewp-status');
        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        self::assertSame(self::EWP_REFERENCE, $send->refs($approvalId)['reference_number']);
        self::assertSame(1, $send->reconciler->poll());
        $this->assertRejected419($send->approval($approvalId), $send->filing(), $send->refs($approvalId), self::EWP_REFERENCE);
        self::assertSame(0, $send->fake->recordings->remaining());
        self::assertCount(1, $send->fake->puts());
    }

    public function testJpkStRecordingsReplayToRejected419(): void
    {
        $send = $this->send();
        JpkTestTools::seedFixedAssets($this->db(), $send->clock);
        JpkTestTools::settings($this->db(), $send->clock)->set('jpk_st.enabled', true, '2026-12-01', null, 'fictional');
        $approvalId = $send->request->jpkSt(2026);
        $send->gate->approve($approvalId, $send->userId);
        $send->fake->load('live-st-upload', 'live-st-status');
        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        self::assertSame(1, $send->reconciler->poll());
        $this->assertRejected419($send->approval($approvalId), $send->filing('JPK_ST'), $send->refs($approvalId), self::ST_REFERENCE);
        self::assertSame(0, $send->fake->recordings->remaining());
    }

    public function testJpkV7mRecordingsReplayToRejected419WithoutClosingPeriod(): void
    {
        $send = $this->v7m(opening: false);
        JpkV7mTestTools::confirmOpening($send, $this->db());
        $approvalId = $send->request->jpkV7m(JpkV7mTestTools::PERIOD);
        $send->gate->approve($approvalId, $send->userId);
        $send->fake->load('live-v7m-upload', 'live-v7m-status');
        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        self::assertSame(1, $send->reconciler->poll());
        $this->assertRejected419($send->approval($approvalId), $send->filing('JPK_V7M', JpkV7mTestTools::PERIOD), $send->refs($approvalId), self::V7M_REFERENCE);
        self::assertNotSame('closed', $send->periods->status(JpkV7mTestTools::PERIOD));
        self::assertSame(0, $send->fake->recordings->remaining());
    }

    /**
     * @param array<string,mixed> $approval
     * @param array<string,mixed> $filing
     * @param array<string,string> $refs
     */
    private function assertRejected419(array $approval, array $filing, array $refs, string $reference): void
    {
        self::assertSame(['rejected', '419'], [$approval['status'], $approval['result_code']]);
        self::assertSame(['rejected', 419, $reference], [$filing['status'], $filing['status_code'], $filing['reference_number']]);
        self::assertArrayNotHasKey('upo_file_id', $refs);
    }
}
