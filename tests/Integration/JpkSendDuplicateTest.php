<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\JpkSendTestTools;

/**
 * Kryterium `jpk-send-duplicate` (F5.1): kod 170 przy `InitUploadSigned` i 407 przy `Status` z numerem oryginału.
 * Kokpit pobiera UPO oryginału odczytem `Status/{oryginał}` i kończy w `accepted`; drugiego złożenia nie ma.
 */
final class JpkSendDuplicateTest extends JpkSendTestCase
{
    public function testInitUpload170FetchesOriginalUpoWithoutUpload(): void
    {
        $send = $this->send();
        $approvalId = $send->approved();
        $send->fake->load('api-init-upload-170');

        self::assertSame('accepted', $send->handler->dispatch($approvalId));
        $row = $send->approval($approvalId);
        self::assertSame(['accepted', '170'], [$row['status'], $row['result_code']]);
        $refs = $send->refs($approvalId);
        self::assertSame(JpkSendTestTools::ORIGINAL, $refs['original_reference_number']);
        self::assertArrayNotHasKey('reference_number', $refs);
        self::assertStringContainsString(JpkSendTestTools::ORIGINAL, $send->files->read($refs['upo_file_id']));
        $filing = $send->filing();
        self::assertSame(['accepted', JpkSendTestTools::ORIGINAL, 170, 'jpk'], [$filing['status'], $filing['reference_number'], $filing['status_code'], $filing['submitted_via']]);
        // Zero drugich złożeń: jedno `InitUploadSigned`, bez Put Blob i `FinishUpload`.
        self::assertSame(1, $send->fake->requests('/InitUploadSigned'));
        self::assertSame([], $send->fake->puts());
        self::assertSame(0, $send->fake->requests('/FinishUpload'));
        self::assertSame(0, $send->fake->recordings->remaining());
        EvidenceArtefacts::write('db/jpk-send-duplicate-170-approval.csv', EvidenceArtefacts::csv($this->db()->fetchAllAssociative('SELECT id, status, result_code, external_refs FROM approval WHERE id=?', [$approvalId])));
    }

    public function testStatus407FetchesOriginalUpo(): void
    {
        $send = $this->send();
        $approvalId = $send->approved();
        $send->loadUpload();
        $send->fake->load('api-status-407');

        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        self::assertSame(1, $send->reconciler->poll());
        $row = $send->approval($approvalId);
        self::assertSame(['accepted', '407'], [$row['status'], $row['result_code']]);
        $refs = $send->refs($approvalId);
        self::assertSame([JpkSendTestTools::REFERENCE, JpkSendTestTools::ORIGINAL], [$refs['reference_number'], $refs['original_reference_number']]);
        self::assertStringContainsString(JpkSendTestTools::ORIGINAL, $send->files->read($refs['upo_file_id']));
        self::assertSame(['accepted', 407], [$send->filing()['status'], $send->filing()['status_code']]);
        self::assertSame(1, $send->fake->requests('/InitUploadSigned'));
        self::assertCount(1, $send->fake->puts());
        self::assertSame(1, $send->fake->requests('/FinishUpload'));
        self::assertSame(0, $send->fake->recordings->remaining());
        EvidenceArtefacts::write('db/jpk-send-duplicate-407-approval.csv', EvidenceArtefacts::csv($this->db()->fetchAllAssociative('SELECT id, status, result_code, external_refs FROM approval WHERE id=?', [$approvalId])));
    }
}
