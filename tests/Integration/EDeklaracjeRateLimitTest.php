<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\EDeklaracje\EDeklaracjeGateway;
use App\Messenger\Message\ApprovalDispatchWaitingMessage;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\VatUeTestTools;

/**
 * Kryterium `edeklaracje-rate-limit` (F5.5, D-66): licznik `edeklaracje.send` wyczerpany albo `blocked_until`.
 * Zatwierdzenie VAT-UE zostaje `approved`, a zadanie `approval.dispatch_waiting` próbuje ponownie; zero wywołań
 * `edeklaracje`. W nowym oknie dobowym wysyłka rusza. Dane fikcyjne.
 */
final class EDeklaracjeRateLimitTest extends VatUeTestCase
{
    public function testExhaustedCounterKeepsApprovedWithoutAnyEDeklaracjeCall(): void
    {
        $send = $this->vatUe('accepted', ['jpk.init_upload' => 10, 'edeklaracje.send' => 1]);
        $approvalId = $send->request->vatUe(VatUeTestTools::PERIOD);
        $send->gate->approve($approvalId, $send->userId);
        $send->edeklaracje->rates->consume('test', 'edeklaracje', EDeklaracjeGateway::BUCKET_SEND);
        self::assertSame('rate_limited', $send->edeklaracjeWriter->waitingReason('test'));

        self::assertNull($send->handler->dispatch($approvalId));
        $send->handler->onDispatchWaiting(new ApprovalDispatchWaitingMessage('approval.dispatch_waiting'));
        self::assertSame('approved', $send->approval($approvalId)['status']);
        self::assertSame('approved', $send->filing('VAT_UE', VatUeTestTools::PERIOD)['status']);
        self::assertSame(0, $send->count("SELECT count(*) FROM connector_call WHERE connector='edeklaracje'"));
        self::assertSame([], $send->edeklaracje->recordings->requests);
        EvidenceArtefacts::write('db/edeklaracje-rate-limit-rate_counter.csv', EvidenceArtefacts::csv($this->db()->fetchAllAssociative("SELECT environment, connector, bucket, window_start, count, blocked_until FROM rate_counter WHERE connector='edeklaracje' ORDER BY window_start")));

        // Nowe okno dobowe (00:30 w Warszawie): `approval.dispatch_waiting` wysyła.
        $send->clock->modify('2026-12-10T23:30:00Z');
        $send->edeklaracje->load('soap-send');
        $send->handler->onDispatchWaiting(new ApprovalDispatchWaitingMessage('approval.dispatch_waiting'));
        self::assertSame('submitted', $send->approval($approvalId)['status']);
        self::assertSame(1, $send->count("SELECT count(*) FROM connector_call WHERE connector='edeklaracje' AND operation='send_unsign_document'"));
    }

    public function testBlockedUntilKeepsApproved(): void
    {
        $send = $this->vatUe('accepted');
        $approvalId = $send->request->vatUe(VatUeTestTools::PERIOD);
        $send->gate->approve($approvalId, $send->userId);
        $now = \DateTimeImmutable::createFromInterface($send->clock->now());
        $send->edeklaracje->rates->blockUntil('test', 'edeklaracje', EDeklaracjeGateway::BUCKET_SEND, $now, $now->modify('+10 minutes'));

        self::assertNull($send->handler->dispatch($approvalId));
        self::assertSame('approved', $send->approval($approvalId)['status']);
        self::assertSame(0, $send->count("SELECT count(*) FROM connector_call WHERE connector='edeklaracje'"));
    }
}
