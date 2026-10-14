<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Messenger\Message\ApprovalDispatchMessage;
use App\Messenger\Message\ApprovalDispatchWaitingMessage;
use App\Messenger\OfficialWritesRetryGuard;
use App\Tests\Support\CorrectionTestTools;
use App\Tests\Support\EvidenceArtefacts;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;

/**
 * Kryterium `ksef-send-timeout-reconcile` (F3.3): timeout po wysłaniu faktury daje `unknown` bez ponowień. Ani drugi
 * przebieg workera, ani `approval.dispatch_waiting`, ani `messenger:failed:retry` nie wysyłają ponownie.
 * `SubmissionReconciler` tylko odczytem (lista błędnych, faktury sesji ze zgodnym `invoiceHash`, UPO) ustala `accepted`.
 */
final class KsefSendTimeoutReconcileTest extends KsefSendTestCase
{
    public function testTimeoutGivesUnknownAndReadOnlyReconciliationAccepts(): void
    {
        $send = $this->send();
        $id = $send->approved();
        $approvalId = $send->sales->approvalId($id);
        $send->load('Latarnia/status-available.json', 'Ksef/send-timeout.json');

        self::assertSame('unknown', $send->handler->dispatch($approvalId));
        $row = $send->approval($approvalId);
        self::assertSame(['unknown', 'exception'], [$row['status'], $row['result_code']]);
        $refs = $send->refs($approvalId);
        self::assertArrayHasKey('session_reference', $refs);
        self::assertArrayNotHasKey('invoice_reference', $refs);
        self::assertSame('unknown', $send->invoice($id)['status']);
        self::assertSame(1, $send->sales->count("SELECT count(*) FROM connector_call WHERE connector='ksef' AND operation='invoice_send' AND error_code='network_error'"));

        // Bez ponowień: drugi worker, zadanie czekających i `messenger:failed:retry` (strażnik `official_writes`).
        self::assertNull($send->handler->dispatch($approvalId));
        $send->handler->onDispatchWaiting(new ApprovalDispatchWaitingMessage('approval.dispatch_waiting'));
        $next = new class implements MiddlewareInterface {
            public int $calls = 0;
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                ++$this->calls;
                return $envelope;
            }
        };
        $guard = new OfficialWritesRetryGuard(new NullLogger());
        $guard->handle(new Envelope(new ApprovalDispatchMessage($approvalId), [new ReceivedStamp('failed'), new SentToFailureTransportStamp('official_writes')]), new StackMiddleware($next));
        self::assertSame(0, $next->calls);
        // Kontrola: ta sama wiadomość odebrana z `official_writes` przechodzi do handlera (który i tak nic nie robi przy `unknown`).
        $guard->handle(new Envelope(new ApprovalDispatchMessage($approvalId), [new ReceivedStamp('official_writes')]), new StackMiddleware($next));
        self::assertSame(1, $next->calls);
        self::assertSame(1, $send->calls('invoice_send'));
        self::assertSame(1, $send->calls('session_open'));

        $send->load('Ksef/reconcile-session-accepted.json');
        self::assertSame(1, $send->reconciler->reconcile());
        $row = $send->approval($approvalId);
        self::assertSame(['accepted', '200'], [$row['status'], $row['result_code']]);
        $refs = $send->refs($approvalId);
        self::assertSame(['session_invoices', CorrectionTestTools::KSEF_NUMBER], [$refs['reconciled_by'], $refs['ksef_number']]);
        self::assertSame(['approval.requested', 'approval.approved', 'dispatch.started', 'dispatch.unknown', 'reconcile.resolved'], $send->audit($approvalId));
        $invoice = $send->invoice($id);
        self::assertSame(['accepted', CorrectionTestTools::KSEF_NUMBER], [$invoice['status'], $invoice['ksef_number']]);
        self::assertSame(1, $send->sales->count("SELECT count(*) FROM stored_file WHERE kind='upo' AND id=?", [$refs['upo_file_id']]));
        // Rekoncyliacja nie wysyła ani nie zamyka sesji: zero drugich wywołań zapisujących.
        self::assertSame(1, $send->calls('invoice_send'));
        self::assertSame(1, $send->calls('session_open'));
        self::assertSame(0, $send->calls('session_close'));
        self::assertSame(0, $send->recordings->remaining());
        EvidenceArtefacts::write('db/ksef-send-timeout-reconcile-connector_call.csv', EvidenceArtefacts::csv($this->db()->fetchAllAssociative("SELECT operation, http_method, path, http_status, error_code, approval_id, request_sha256 FROM connector_call WHERE connector='ksef' ORDER BY sequence")));
    }
}
