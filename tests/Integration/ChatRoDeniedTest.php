<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\EvidenceArtefacts;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;

/** chat-ro-denied (cc-28): połączenie `chat` z rolą chat_ro czyta tylko widoki `chat_*`; zapisy i tabele kończą się błędem bazy. */
final class ChatRoDeniedTest extends DatabaseTestCase
{
    private const VIEWS = ['chat_counterparty', 'chat_filing', 'chat_fx_rate', 'chat_invoice', 'chat_obligation', 'chat_payment', 'chat_revenue', 'chat_verification'];

    public function testChatConnectionIsReadOnlyAndSeesOnlyViews(): void
    {
        $chat = $this->chat();
        self::assertSame('chat_ro', $chat->fetchOne('SELECT current_user'));
        self::assertSame('2s', $chat->fetchOne('SHOW statement_timeout'));
        self::assertSame('on', $chat->fetchOne('SHOW default_transaction_read_only'));
        $statements = [
            "INSERT INTO invoice (id) VALUES ('00000000-0000-7000-8000-000000000001')",
            "UPDATE invoice SET net_total = 0 WHERE id = '00000000-0000-7000-8000-000000000001'",
            "DELETE FROM invoice WHERE id = '00000000-0000-7000-8000-000000000001'",
            "INSERT INTO chat_invoice (id) VALUES ('00000000-0000-7000-8000-000000000001')",
            "UPDATE chat_invoice SET net_total = 0 WHERE id = '00000000-0000-7000-8000-000000000001'",
            "DELETE FROM chat_invoice WHERE id = '00000000-0000-7000-8000-000000000001'",
            'SELECT count(*) FROM invoice',
            'SELECT pseudonym_map FROM chat_session',
            'SELECT count(*) FROM chat_message',
            'SELECT count(*) FROM audit_log',
            'SELECT count(*) FROM connector_call',
            'SELECT count(*) FROM credential_state',
            'SELECT count(*) FROM setting',
        ];
        $log = [];
        foreach ($statements as $statement) {
            try {
                $chat->executeQuery($statement);
                self::fail('Rola chat_ro musi odrzucić: ' . $statement);
            } catch (DriverException $exception) {
                // 42501: brak uprawnień; 25006: transakcja tylko do odczytu.
                self::assertContains($exception->getSQLState(), ['42501', '25006'], $statement);
                $log[] = sprintf('%s → SQLSTATE %s: %s', $statement, $exception->getSQLState(), self::message($exception));
            }
        }
        foreach (self::VIEWS as $view) {
            self::assertIsInt($chat->fetchOne('SELECT count(*) FROM ' . $view));
            $log[] = sprintf('SELECT count(*) FROM %s → ok', $view);
        }
        EvidenceArtefacts::write('cmd/chat-ro-denied.txt', "current_user=chat_ro\nstatement_timeout=2s\ndefault_transaction_read_only=on\n" . implode("\n", $log) . "\n");
    }

    public function testRoleHasNoTablePrivilegesAndSelectOnlyOnChatViews(): void
    {
        $tables = $this->connection->fetchFirstColumn(<<<'SQL'
SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p', 'S', 'm', 'f')
 AND (has_table_privilege('chat_ro', c.oid, 'SELECT') OR has_table_privilege('chat_ro', c.oid, 'INSERT')
  OR has_table_privilege('chat_ro', c.oid, 'UPDATE') OR has_table_privilege('chat_ro', c.oid, 'DELETE')
  OR has_table_privilege('chat_ro', c.oid, 'TRUNCATE') OR has_table_privilege('chat_ro', c.oid, 'REFERENCES') OR has_table_privilege('chat_ro', c.oid, 'TRIGGER'))
SQL);
        self::assertSame([], $tables);
        $readable = $this->connection->fetchFirstColumn("SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'v' AND has_table_privilege('chat_ro', c.oid, 'SELECT') ORDER BY 1");
        self::assertSame(self::VIEWS, $readable);
        $writable = $this->connection->fetchFirstColumn("SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'v' AND (has_table_privilege('chat_ro', c.oid, 'INSERT') OR has_table_privilege('chat_ro', c.oid, 'UPDATE') OR has_table_privilege('chat_ro', c.oid, 'DELETE') OR has_table_privilege('kokpit_app', c.oid, 'INSERT') OR has_table_privilege('kokpit_app', c.oid, 'UPDATE') OR has_table_privilege('kokpit_app', c.oid, 'DELETE'))");
        self::assertSame([], $writable);
        // Widoki właściciela bez security_invoker: chat_ro nie potrzebuje praw do tabel.
        foreach ($this->connection->fetchAllAssociative("SELECT c.relname, pg_get_userbyid(c.relowner) AS owner, c.reloptions::text AS options FROM pg_class c WHERE c.relkind = 'v' AND c.relname LIKE 'chat\\_%'") as $view) {
            self::assertSame('kokpit_owner', $view['owner']);
            self::assertSame('{security_invoker=false}', $view['options']);
        }
    }

    public function testDefaultEntityManagerNeverUsesChatRole(): void
    {
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        self::assertSame('kokpit_app', $manager->getConnection()->fetchOne('SELECT current_user'));
        self::assertSame('kokpit_app', $this->connection->fetchOne('SELECT current_user'));
    }

    private function chat(): Connection
    {
        $chat = self::getContainer()->get('doctrine.dbal.chat_connection');
        self::assertInstanceOf(Connection::class, $chat);
        return $chat;
    }

    private static function message(DriverException $exception): string
    {
        $previous = $exception->getPrevious();
        $text = $previous !== null ? $previous->getMessage() : $exception->getMessage();
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
