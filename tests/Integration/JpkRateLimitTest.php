<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\Jpk\JpkGateway;
use App\Messenger\Message\ApprovalDispatchWaitingMessage;
use App\Tests\Support\EvidenceArtefacts;

/**
 * Kryterium `jpk-rate-limit` (F5.1): licznik `jpk.init_upload` wyczerpany. Zatwierdzenie zostaje `approved`, a zadanie
 * `approval.dispatch_waiting` próbuje ponownie; zero wywołań `jpk`. Po północy (nowe okno licznika) wysyłka rusza.
 */
final class JpkRateLimitTest extends JpkSendTestCase
{
    public function testExhaustedCounterKeepsApprovedWithoutAnyJpkCall(): void
    {
        $send = $this->send(limits: ['jpk.init_upload' => 2]);
        $approvalId = $send->approved();
        $send->fake->rates->consume('test', 'jpk', JpkGateway::BUCKET_INIT);
        $send->fake->rates->consume('test', 'jpk', JpkGateway::BUCKET_INIT);
        self::assertSame('rate_limited', $send->writer->waitingReason('test'));

        self::assertNull($send->handler->dispatch($approvalId));
        $send->handler->onDispatchWaiting(new ApprovalDispatchWaitingMessage('approval.dispatch_waiting'));
        self::assertSame('approved', $send->approval($approvalId)['status']);
        self::assertSame('approved', $send->filing()['status']);
        self::assertSame(0, $send->calls());
        self::assertSame([], $send->fake->recordings->requests);
        EvidenceArtefacts::write('db/jpk-rate-limit-rate_counter.csv', EvidenceArtefacts::csv($this->db()->fetchAllAssociative("SELECT environment, connector, bucket, window_start, count, blocked_until FROM rate_counter WHERE connector='jpk' ORDER BY window_start")));

        // Nowe okno dobowe (00:30 w Warszawie, przed wygaśnięciem `approved` po 24 h): `approval.dispatch_waiting` wysyła.
        $send->clock->modify('2027-01-15T23:30:00Z');
        $send->loadUpload();
        $send->handler->onDispatchWaiting(new ApprovalDispatchWaitingMessage('approval.dispatch_waiting'));
        self::assertSame('submitted', $send->approval($approvalId)['status']);
        self::assertSame(1, $send->calls('init_upload_signed'));
    }

    public function testBlockedUntilKeepsApproved(): void
    {
        $send = $this->send();
        $approvalId = $send->approved();
        $now = \DateTimeImmutable::createFromInterface($send->clock->now());
        $send->fake->rates->blockUntil('test', 'jpk', JpkGateway::BUCKET_INIT, $now, $now->modify('+10 minutes'));

        self::assertNull($send->handler->dispatch($approvalId));
        self::assertSame('approved', $send->approval($approvalId)['status']);
        self::assertSame(0, $send->calls());
    }
}
