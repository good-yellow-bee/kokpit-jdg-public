<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Chat\ChatRetention;
use App\Tests\Support\ChatTestTools;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use Symfony\Component\Clock\MockClock;

/** chat-retention: `retention.purge` usuwa rozmowę 12 miesięcy po `closed_at` z wiadomościami; szkic zostaje bez rozmowy. */
final class ChatRetentionTest extends DatabaseTestCase
{
    public function testClosedSessionsOlderThanRetentionArePurged(): void
    {
        $clock = new MockClock('2026-10-14T10:00:00Z');
        $owner = F1TestTools::owner($this->connection, $clock);
        $recipient = ChatTestTools::counterparty($this->connection, $clock, 'test', 'Fikcyjny Odbiorca', 'PL', ['is_client' => 'true']);
        $expired = ChatTestTools::session($this->connection, $owner, '2025-10-01T09:00:00Z', '2025-10-13T10:00:00Z', 3);
        $recent = ChatTestTools::session($this->connection, $owner, '2025-11-01T09:00:00Z', '2025-11-14T10:00:00Z');
        $open = ChatTestTools::session($this->connection, $owner, '2025-01-10T09:00:00Z', null);
        $draft = ChatTestTools::draft($this->connection, $clock, $expired, $recipient);
        $kept = ChatTestTools::draft($this->connection, $clock, $recent, $recipient);
        $months = self::getContainer()->getParameter('chat.retention_months');
        self::assertSame(12, $months);

        self::assertSame(1, (new ChatRetention($this->connection, $clock, $months))->purge());

        self::assertSame([$recent, $open], $this->connection->fetchFirstColumn('SELECT id::text FROM chat_session WHERE id IN (?, ?, ?) ORDER BY started_at DESC', [$expired, $recent, $open]));
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM chat_message WHERE chat_session_id = ?', [$expired]));
        self::assertSame(4, $this->connection->fetchOne('SELECT count(*) FROM chat_message WHERE chat_session_id IN (?, ?)', [$recent, $open]));
        $rows = $this->connection->fetchAllAssociative('SELECT id, chat_session_id, kind, status, version FROM draft WHERE id IN (?, ?) ORDER BY id', [$draft, $kept]);
        self::assertSame([[$draft, null], [$kept, $recent]], array_map(static fn(array $row): array => [$row['id'], $row['chat_session_id']], $rows));
        EvidenceArtefacts::write('db/chat-retention-draft.csv', EvidenceArtefacts::csv($rows));
        self::assertSame(0, (new ChatRetention($this->connection, $clock, $months))->purge());
    }
}
