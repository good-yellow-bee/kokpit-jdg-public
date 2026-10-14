<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\ChatTestTools;
use App\Tests\Support\F1TestTools;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Clock\MockClock;

/** chat-history-append-only: `chat_message` tylko dopisywane; `seq` unikalny w rozmowie. */
final class ChatHistoryAppendOnlyTest extends DatabaseTestCase
{
    public function testApplicationRoleCannotUpdateMessages(): void
    {
        $session = ChatTestTools::session($this->connection, F1TestTools::owner($this->connection, new MockClock('2026-10-14T10:00:00Z')), '2026-10-14T09:00:00Z', null);
        foreach (["UPDATE chat_message SET content = '[]'::jsonb WHERE chat_session_id = ?", 'DELETE FROM chat_message WHERE chat_session_id = ?'] as $statement) {
            $this->connection->executeStatement('SAVEPOINT append_only');
            try {
                $this->connection->executeStatement($statement, [$session]);
                self::fail('Historia musi odrzucić: ' . $statement);
            } catch (DriverException $exception) {
                // 42501: rola aplikacji nie ma UPDATE ani DELETE; trigger to druga bariera.
                self::assertContains($exception->getSQLState(), ['42501', 'P0001']);
            }
            $this->connection->executeStatement('ROLLBACK TO SAVEPOINT append_only');
        }
        self::assertSame(2, $this->connection->fetchOne('SELECT count(*) FROM chat_message WHERE chat_session_id = ?', [$session]));
        self::assertSame('Fikcyjna wiadomość 1', $this->connection->fetchOne("SELECT content->0->>'text' FROM chat_message WHERE chat_session_id = ? AND seq = 1", [$session]));
        // Druga bariera niezależna od uprawnień: trigger odrzucający UPDATE, włączony.
        self::assertSame('O', $this->connection->fetchOne("SELECT tgenabled FROM pg_trigger WHERE tgname = 'chat_message_append_only' AND tgrelid = 'chat_message'::regclass"));
    }

    public function testDuplicateSeqIsRejected(): void
    {
        $session = ChatTestTools::session($this->connection, F1TestTools::owner($this->connection, new MockClock('2026-10-14T10:00:00Z')), '2026-10-14T09:00:00Z', null);
        $this->expectException(UniqueConstraintViolationException::class);
        ChatTestTools::message($this->connection, $session, 2, 'user', '2026-10-14T09:05:00Z');
    }
}
