<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\Claude\ClaudeError;
use App\Tests\Support\ChatTestTools;
use App\Tests\Support\ClaudeTestTools;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\EvidenceArtefacts;
use Symfony\Component\Clock\MockClock;

/** chat-rate-limit: 429 z `Retry-After` daje `blocked_until` w `claude.messages`; do tej chwili zero żądań, bez kolejki. */
final class ChatRateLimitTest extends ClaudeTestCase
{
    public function testRetryAfterBlocksTheBucketWithoutQueueOrRetry(): void
    {
        $clock = new MockClock('2026-10-14T10:00:00Z');
        ChatTestTools::seed($this->connection, $clock);
        [$session] = ClaudeTestTools::start($this->connection, $clock);
        $requests = [];
        $mock = ClaudeTestTools::mock([ClaudeTestTools::response('messages-rate-limit', 429, ['retry-after' => '120']), ClaudeTestTools::response('messages-end-turn')], $requests);
        $turn = ClaudeTestTools::turn($this->connection, $clock, $mock);

        $limited = $turn->ask($session, 'Fikcyjne pytanie przy limicie.');
        self::assertSame(ClaudeError::RateLimited, $limited->error);
        self::assertEquals(new \DateTimeImmutable('2026-10-14T10:02:00Z'), $limited->blockedUntil);
        self::assertStringContainsString('2026-10-14 12:02', $limited->message());
        self::assertCount(1, $requests);

        $clock->modify('+60 seconds');
        $blocked = $turn->ask($session, 'Kolejne fikcyjne pytanie przed blocked_until.');
        self::assertSame(ClaudeError::RateLimited, $blocked->error);
        self::assertSame(0, $blocked->requests);
        self::assertCount(1, $requests);
        // Bez kolejki: pytanie odrzucone przed API nie trafia do historii ani do Messenger.
        self::assertSame(1, F1TestTools::count($this->connection, "SELECT count(*) FROM chat_message WHERE chat_session_id = ? AND role = 'user'", [$session]));

        $clock->modify('+61 seconds');
        self::assertNull($turn->ask($session, 'Fikcyjne pytanie po blocked_until.')->error);
        self::assertCount(2, $requests);

        $rows = $this->connection->fetchAllAssociative("SELECT environment, connector, bucket, window_start, count, blocked_until FROM rate_counter WHERE connector = 'claude' ORDER BY window_start");
        self::assertSame('claude.messages', $rows[0]['bucket']);
        self::assertNotNull($rows[0]['blocked_until']);
        self::assertSame(120, F1TestTools::count($this->connection, "SELECT retry_after_s FROM connector_call WHERE connector = 'claude' AND http_status = 429"));
        EvidenceArtefacts::write('db/chat-rate-limit-rate_counter.csv', EvidenceArtefacts::csv($rows));
    }
}
