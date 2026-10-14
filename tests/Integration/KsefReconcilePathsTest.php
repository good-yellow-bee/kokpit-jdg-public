<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\CorrectionTestTools;

/**
 * Kryterium `ksef-reconcile-paths` (F3.3): ścieżki rekoncyliacji `unknown` wyłącznie odczytem (docs/modules/ksef.md,
 * „Rekoncyliacja w KSeF”): tylko referencja sesji i faktura na liście błędnych → `rejected`; brak referencji i faktura
 * w sesji z dnia wysyłki → `accepted`; brak referencji i dopasowanie z syncu Subject1 (`ksef_token_read`) → `accepted`;
 * brak wyniku → `unknown`, push po godzinie i „Ponów ręcznie” w panelu.
 */
final class KsefReconcilePathsTest extends KsefSendTestCase
{
    public function testSessionReferenceWithInvoiceOnFailedListIsRejected(): void
    {
        $send = $this->send();
        $id = $send->approved();
        $approvalId = $send->sales->approvalId($id);
        $send->load('Latarnia/status-available.json', 'Ksef/send-timeout.json');
        self::assertSame('unknown', $send->handler->dispatch($approvalId));

        $send->load('Ksef/reconcile-session-failed.json');
        self::assertSame(1, $send->reconciler->reconcile());
        $row = $send->approval($approvalId);
        self::assertSame(['rejected', '450'], [$row['status'], $row['result_code']]);
        self::assertSame('session_failed', $send->refs($approvalId)['reconciled_by']);
        self::assertSame('rejected', $send->invoice($id)['status']);
        self::assertSame(1, $send->calls('invoice_send'));
        self::assertSame(0, $send->recordings->remaining());
    }

    public function testNoReferenceWithInvoiceInSessionOfSendDayIsAccepted(): void
    {
        $send = $this->send();
        $id = $send->approved();
        $approvalId = $send->sales->approvalId($id);
        $send->lostWorker($approvalId);
        self::assertSame([], $send->refs($approvalId));

        $send->load('Ksef/reconcile-day-sessions.json');
        // Stare `dispatching` → `unknown` (`worker_lost`), potem rozstrzygnięcie w tym samym przebiegu.
        self::assertSame(2, $send->reconciler->reconcile());
        $row = $send->approval($approvalId);
        self::assertSame(['accepted', '200'], [$row['status'], $row['result_code']]);
        $refs = $send->refs($approvalId);
        self::assertSame(['sessions_list', CorrectionTestTools::KSEF_NUMBER], [$refs['reconciled_by'], $refs['ksef_number']]);
        self::assertSame(['accepted', CorrectionTestTools::KSEF_NUMBER], [$send->invoice($id)['status'], $send->invoice($id)['ksef_number']]);
        $list = array_values(array_filter($send->recordings->requests, static fn(array $request): bool => $request['path'] === '/v2/sessions'));
        self::assertCount(1, $list);
        self::assertStringContainsString('sessionType=Online', $list[0]['query']);
        self::assertStringContainsString('dateCreatedFrom=2026-11-16T00:00:00+01:00', $list[0]['query']);
        self::assertSame(0, $send->calls('session_open') + $send->calls('invoice_send') + $send->calls('session_close'));
        self::assertSame(0, $send->recordings->remaining());
    }

    public function testNoReferenceMatchedBySubject1SyncIsAccepted(): void
    {
        $send = $this->send();
        $id = $send->approved();
        $approvalId = $send->sales->approvalId($id);
        $send->lostWorker($approvalId);
        $send->load('Ksef/reconcile-day-empty.json');
        self::assertSame(1, $send->reconciler->reconcile());
        self::assertSame('unknown', $send->approval($approvalId)['status']);

        // Sync Subject1 (token `ksef_token_read`) przynosi fakturę o tym `kind` i P_2 z bajtami równymi `xml_sent`.
        self::assertSame('exported', $send->subject1($this->corrections($send->sales), $send->payload($approvalId), CorrectionTestTools::KSEF_NUMBER));
        $row = $send->approval($approvalId);
        self::assertSame(['accepted', 'subject1_match'], [$row['status'], $row['result_code']]);
        self::assertSame('subject1', $send->refs($approvalId)['reconciled_by']);
        $audit = $send->audit($approvalId);
        self::assertSame('reconcile.resolved', end($audit));
        $invoice = $send->invoice($id);
        self::assertSame(['accepted', CorrectionTestTools::KSEF_NUMBER, 'online'], [$invoice['status'], $invoice['ksef_number'], $invoice['ksef_issue_mode']]);
        self::assertNotNull($invoice['ksef_number_assigned_at']);
        self::assertSame(1, $send->sales->count("SELECT count(*) FROM invoice WHERE direction='sale'"));
        self::assertSame(0, $send->calls('session_open') + $send->calls('invoice_send'));
    }

    public function testNoResultStaysUnknownAndPanelProposesManualRetry(): void
    {
        $send = $this->send();
        $id = $send->approved();
        $approvalId = $send->sales->approvalId($id);
        $send->lostWorker($approvalId);
        $send->load('Ksef/reconcile-day-empty.json', 'Ksef/reconcile-day-empty.json');
        self::assertSame(1, $send->reconciler->reconcile());
        self::assertSame('unknown', $send->approval($approvalId)['status']);
        self::assertSame(0, $send->sales->count("SELECT count(*) FROM notification WHERE category='connector_failure' AND approval_id=?", [$approvalId]));

        $send->sales->clock->sleep(61 * 60);
        self::assertSame(0, $send->reconciler->reconcile());
        self::assertSame('unknown', $send->approval($approvalId)['status']);
        self::assertSame(1, $send->sales->count("SELECT count(*) FROM notification WHERE category='connector_failure' AND approval_id=?", [$approvalId]));
        self::assertTrue($send->writer->retryAllowed($approvalId, $send->refs($approvalId)));
        self::assertSame(0, $send->calls('session_open') + $send->calls('invoice_send'));

        $page = $this->approvalsPage();
        $section = substr($page, (int) strpos($page, 'id="approval-' . $approvalId . '"'));
        self::assertStringContainsString('sales_invoice przez ksef: unknown', $section);
        self::assertStringContainsString('/approvals/' . $approvalId . '/retry', $section);
        self::assertStringContainsString('Ponów ręcznie', $section);
    }
}
