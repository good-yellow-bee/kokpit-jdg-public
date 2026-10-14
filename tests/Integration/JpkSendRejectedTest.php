<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Approval\InvalidTransitionException;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\JpkSendTestTools;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Kryterium `jpk-send-rejected` (F5.1): `Status` 401, 408, 428 i 422 oraz `InitUploadSigned` 158. Odrzucenie zapisuje
 * kod w `filing.status_code` i wysyła push; 158 kończy się bez numeru referencyjnego. Ponowienie tylko nowym
 * zatwierdzeniem.
 */
final class JpkSendRejectedTest extends JpkSendTestCase
{
    /** @return iterable<string,array{int}> */
    public static function codes(): iterable
    {
        foreach ([401, 408, 428, 422] as $code) {
            yield 'status ' . $code => [$code];
        }
    }

    #[DataProvider('codes')]
    public function testStatusCodeRejectsWithFilingStatusCodeAndPush(int $code): void
    {
        $send = $this->send();
        $approvalId = $send->approved();
        $send->loadUpload();
        $send->fake->load('api-status-' . $code);

        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        self::assertSame(1, $send->reconciler->poll());
        $row = $send->approval($approvalId);
        self::assertSame(['rejected', (string) $code], [$row['status'], $row['result_code']]);
        $filing = $send->filing();
        self::assertSame(['rejected', $code, JpkSendTestTools::REFERENCE], [$filing['status'], $filing['status_code'], $filing['reference_number']]);
        self::assertSame(1, $send->pushes($approvalId));
        self::assertSame(1, $send->calls('init_upload_signed'));
    }

    public function testInitUpload158RejectsWithoutReferenceAndRetriesOnlyByNewApproval(): void
    {
        $send = $this->send();
        $approvalId = $send->approved();
        $send->fake->load('api-init-upload-158');

        self::assertSame('rejected', $send->handler->dispatch($approvalId));
        $row = $send->approval($approvalId);
        self::assertSame(['rejected', '158'], [$row['status'], $row['result_code']]);
        self::assertArrayNotHasKey('reference_number', $send->refs($approvalId));
        $filing = $send->filing();
        self::assertSame(['rejected', 158, null], [$filing['status'], $filing['status_code'], $filing['reference_number']]);
        self::assertSame(1, $send->pushes($approvalId));
        self::assertSame([], $send->fake->puts());

        // Ponowienie: nie z `rejected`, tylko nową prośbą i nowym zatwierdzeniem ze step-upem.
        try {
            $send->gate->retry($approvalId, $send->userId, $send->connectors);
            self::fail('Ponowienie z rejected przeszło.');
        } catch (InvalidTransitionException) {
        }
        $next = $send->request->jpkEwp(JpkSendTestTools::YEAR);
        self::assertNotSame($approvalId, $next);
        self::assertSame(['pending', 'ready'], [$send->approval($next)['status'], $send->filing()['status']]);
        self::assertSame('rejected', $send->approval($approvalId)['status']);
        self::assertSame(1, $send->calls('init_upload_signed'));
        EvidenceArtefacts::write('db/jpk-send-rejected-158-approval.csv', EvidenceArtefacts::csv($this->db()->fetchAllAssociative('SELECT id, status, result_code, external_refs FROM approval WHERE subject_type=? ORDER BY requested_at', ['filing'])));
    }
}
