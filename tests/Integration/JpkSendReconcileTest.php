<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\JpkSendTestTools;

/**
 * Kryterium `jpk-send-reconcile` (F5.1): timeout po `FinishUpload`, `Status` 411, `Status` 300 przed i po
 * `FinishUpload`, potem wgranie UPO. Wynik nieznany zostaje `unknown` bez ponowień; `SubmissionReconciler` tylko
 * czyta `Status`; 300 przed `FinishUpload` → `rejected`; wgranie UPO → `cancelled` z `manual_filing` i `accepted`
 * jako `manual`.
 */
final class JpkSendReconcileTest extends JpkSendTestCase
{
    public function testFinishTimeoutStaysUnknownUntilManualUpo(): void
    {
        $send = $this->send();
        $approvalId = $send->approved();
        $send->fake->load('api-init-upload', 'api-put-blob', 'api-finish-upload-timeout');

        self::assertSame('unknown', $send->handler->dispatch($approvalId));
        $row = $send->approval($approvalId);
        self::assertSame('finish_upload_timeout', $row['result_code']);
        $refs = $send->refs($approvalId);
        self::assertSame(JpkSendTestTools::REFERENCE, $refs['reference_number']);
        self::assertArrayNotHasKey('finish_upload_at', $refs);
        self::assertSame('unknown', $send->filing()['status']);

        // Rekoncyliacja czyta `Status`: 411 bez własnego złożenia z tym sha256 zostawia `unknown`.
        $send->fake->load('api-status-411');
        self::assertSame(0, $send->reconciler->reconcile());
        self::assertSame('unknown', $send->approval($approvalId)['status']);
        self::assertFalse($send->writer->retryAllowed($approvalId, $send->refs($approvalId)));
        try {
            $send->gate->retry($approvalId, $send->userId, $send->connectors);
            self::fail('Ponowienie po FinishUpload bez wyniku przeszło.');
        } catch (\DomainException) {
        }

        // Wgranie UPO złożenia zrobionego poza kokpitem: `cancelled` z `manual_filing`, złożenie `accepted` jako `manual`.
        $send->manualUpo->record($send->filingId(), $send->userId, '<Potwierdzenie fictional="true"><Uwagi>Fikcyjne UPO wgrane ręcznie.</Uwagi></Potwierdzenie>', 'application/xml');
        $row = $send->approval($approvalId);
        self::assertSame(['cancelled', 'manual_filing'], [$row['status'], $row['cancel_reason']]);
        $filing = $send->filing();
        self::assertSame(['accepted', 'manual'], [$filing['status'], $filing['submitted_via']]);
        self::assertNotNull($filing['accepted_at']);
        self::assertSame(1, $send->count("SELECT count(*) FROM stored_file WHERE kind='upo' AND owner_table='filing' AND owner_id=?", [$filing['id']]));
        self::assertSame(1, $send->count("SELECT count(*) FROM audit_log WHERE action='filing.manual_recorded'"));

        // Jedna wysyłka: jedno `InitUploadSigned`, jeden Put Blob, jedno `FinishUpload`; reszta to odczyty.
        $calls = $this->db()->fetchAllAssociative("SELECT operation, http_method, path, http_status, approval_id FROM connector_call WHERE connector='jpk' ORDER BY sequence");
        self::assertSame(['init_upload_signed', 'put_blob', 'finish_upload', 'status'], array_column($calls, 'operation'));
        self::assertSame(0, $send->fake->recordings->remaining());
        EvidenceArtefacts::write('db/jpk-send-reconcile-connector_call.csv', EvidenceArtefacts::csv($calls));
    }

    public function testStatus411AfterFinishIsUnknownAndAllowsRetry(): void
    {
        $send = $this->send();
        $approvalId = $send->approved();
        $send->loadUpload();
        $send->fake->load('api-status-411');

        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        self::assertSame(1, $send->reconciler->poll());
        $row = $send->approval($approvalId);
        self::assertSame(['unknown', '411'], [$row['status'], $row['result_code']]);
        self::assertSame('411', $send->refs($approvalId)['status_code']);
        self::assertSame(['unknown', 411], [$send->filing()['status'], $send->filing()['status_code']]);
        // 411: identyczny dokument już jest; ponowienie nowym zatwierdzeniem chroni duplikat po stronie MF.
        self::assertTrue($send->writer->retryAllowed($approvalId, $send->refs($approvalId)));
        self::assertSame(1, $send->calls('init_upload_signed'));
    }

    public function testStatus300AfterFinishIsUnknown(): void
    {
        $send = $this->send();
        $approvalId = $send->approved();
        $send->loadUpload();
        $send->fake->load('api-status-300');

        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        self::assertSame(1, $send->reconciler->poll());
        self::assertSame(['unknown', '300'], [$send->approval($approvalId)['status'], $send->approval($approvalId)['result_code']]);
        self::assertSame(1, $send->calls('init_upload_signed'));
    }

    public function testStatus300BeforeFinishIsRejected(): void
    {
        $send = $this->send();
        $approvalId = $send->approved();
        // Worker padł po `InitUploadSigned`: numer referencyjny zapisany, bez Put Blob i `FinishUpload`.
        $transitions = $send->transitions;
        $refs = ['correlation_id' => '0199e0a0-0000-7000-8000-000000000001', 'reference_number' => JpkSendTestTools::REFERENCE, 'timeout_s' => '900', 'init_upload_at' => $transitions->now()];
        $transitions->apply($transitions->lock($approvalId), 'dispatching', 'start', ['dispatching_at' => $transitions->now(), 'external_refs' => json_encode($refs, JSON_THROW_ON_ERROR)]);
        $send->clock->sleep(11 * 60);
        $send->fake->load('api-status-300');

        self::assertSame(2, $send->reconciler->reconcile());
        $row = $send->approval($approvalId);
        self::assertSame(['rejected', '300'], [$row['status'], $row['result_code']]);
        self::assertSame(['rejected', 300], [$send->filing()['status'], $send->filing()['status_code']]);
        self::assertSame(0, $send->calls('init_upload_signed'));
        self::assertSame(1, $send->calls('status'));
        self::assertSame(1, $send->pushes($approvalId));
    }
}
