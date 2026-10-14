<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\EvidenceArtefacts;

/**
 * Kryterium `ksef-send-429` (F3.3): 429 z `Retry-After` przy otwarciu sesji (docs/modules/ksef.md, „Limity i 429”).
 * Nic nie wyszło do urzędu, więc zatwierdzenie wraca do `approved` (`dispatch.deferred`, D-54), `blocked_until`
 * trafia do `ksef.session_open`, do tej chwili kokpit nie woła KSeF, a push idzie według reguły limitów.
 */
final class KsefSend429Test extends KsefSendTestCase
{
    public function testRateLimitedSessionOpenDefersUntilBlockedUntil(): void
    {
        $send = $this->send();
        $id = $send->approved();
        $approvalId = $send->sales->approvalId($id);
        $send->load('Latarnia/status-available.json', 'Ksef/send-429.json');

        self::assertSame('approved', $send->handler->dispatch($approvalId));
        $row = $send->approval($approvalId);
        self::assertSame(['approved', 'rate_limited', null], [$row['status'], $row['result_code'], $row['dispatching_at']]);
        self::assertContains('dispatch.deferred', $send->audit($approvalId));
        self::assertSame('approved', $send->invoice($id)['status']);
        self::assertSame(1, $send->calls('session_open'));
        self::assertSame(0, $send->calls('invoice_send'));
        self::assertSame(1, $send->sales->count("SELECT count(*) FROM connector_call WHERE connector='ksef' AND operation='session_open' AND http_status=429 AND retry_after_s=120"));

        $now = \DateTimeImmutable::createFromInterface($send->sales->clock->now());
        $blocked = $this->db()->fetchOne("SELECT blocked_until FROM rate_counter WHERE environment='test' AND connector='ksef' AND bucket='ksef.session_open'");
        self::assertIsString($blocked);
        self::assertEquals($now->modify('+120 seconds'), new \DateTimeImmutable($blocked));
        self::assertSame(1, $send->sales->count("SELECT count(*) FROM notification WHERE channel='push' AND category='connector_failure' AND dedupe_key LIKE 'test:ksef:ksef.session_open:%:rate_limited:push'"));
        EvidenceArtefacts::write('db/ksef-send-429-rate_counter.csv', EvidenceArtefacts::csv($this->db()->fetchAllAssociative(
            "SELECT environment, connector, bucket, window_start, count, blocked_until FROM rate_counter WHERE connector='ksef' ORDER BY bucket",
        )));

        // Przed `blocked_until`: warunek przejściowy, zero wywołań KSeF i Latarni.
        $send->sales->clock->sleep(60);
        $before = $send->calls(null, 'latarnia');
        self::assertSame('blocked_until', $send->writer->waitingReason('test'));
        self::assertNull($send->handler->dispatch($approvalId));
        self::assertSame('approved', $send->approval($approvalId)['status']);
        self::assertSame(1, $send->calls());
        self::assertSame($before, $send->calls(null, 'latarnia'));

        // Po `blocked_until` zadanie `approval.dispatch_waiting` wysyła normalnie.
        $send->sales->clock->sleep(61);
        $send->load('Latarnia/status-available.json', 'Ksef/send-accepted.json');
        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        self::assertSame(2, $send->calls('session_open'));
        self::assertSame(1, $send->calls('invoice_send'));
        self::assertSame(1, $send->sales->count("SELECT count(*) FROM notification WHERE category='connector_failure'"));
    }
}
