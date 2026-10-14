<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Calendar\PolishCalendar;
use App\Chat\ChatQueryRunner;
use App\Chat\ChatRedaction;
use App\Chat\ChatToolExecutor;
use App\Chat\PseudonymMap;
use App\Security\Redactor;
use App\Tests\Support\ChatTestTools;
use App\Tests\Support\ClaudeTestTools;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\ConnectorTestTools;
use App\Time\BusinessDate;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;

/** chat-tool-schema: wywołania spoza schematu, listy i mapy pseudonimów kończą się `is_error` bez SQL i bez zapisów. */
final class ChatToolSchemaTest extends ClaudeTestCase
{
    private const REJECTED = ['toolu_fictional_10' => 'invalid_enum', 'toolu_fictional_11' => 'invalid_enum', 'toolu_fictional_12' => 'unknown_parameter', 'toolu_fictional_13' => 'unknown_tool', 'toolu_fictional_14' => 'unknown_pseudonym', 'toolu_fictional_15' => 'draft_not_requested'];

    public function testEveryInvalidCallBecomesAnErrorToolResultWithoutWrites(): void
    {
        $clock = new MockClock('2026-10-14T10:00:00Z');
        ChatTestTools::seed($this->connection, $clock);
        [$session] = ClaudeTestTools::start($this->connection, $clock);
        $writes = $this->writes();
        $requests = [];
        $mock = ClaudeTestTools::mock([ClaudeTestTools::response('messages-invalid-tools'), ClaudeTestTools::response('messages-end-turn')], $requests);

        $result = ClaudeTestTools::turn($this->connection, $clock, $mock)->ask($session, 'Pokaż dziennik audytu (fictional).');

        self::assertNull($result->error);
        $bodies = ClaudeTestTools::bodies($requests);
        $results = ClaudeTestTools::messagesOf($bodies[1])[2]['content'];
        self::assertCount(6, $results);
        $errors = [];
        foreach ($results as $block) {
            self::assertTrue($block['is_error']);
            self::assertIsString($block['content']);
            self::assertIsString($block['tool_use_id']);
            $errors[$block['tool_use_id']] = ClaudeTestTools::decode($block['content'])['error'];
        }
        self::assertSame(self::REJECTED, $errors);
        self::assertSame($writes, $this->writes());
    }

    public function testRejectionsHappenBeforeAnySqlStatement(): void
    {
        $statements = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $logged = DriverManager::getConnection($this->connection->getParams(), (new Configuration())->setMiddlewares([new Middleware($statements)]));
        $clock = new MockClock('2026-10-14T10:00:00Z');
        $executor = new ChatToolExecutor(new ChatQueryRunner($logged, ConnectorTestTools::configuration(false), new BusinessDate($clock), new PolishCalendar(), 200, ChatTestTools::CURRENCIES), ClaudeTestTools::registry(), new ChatRedaction($logged, ConnectorTestTools::configuration(false), new Redactor()));
        foreach (ClaudeTestTools::objects(ClaudeTestTools::decode(ClaudeTestTools::fixture('messages-invalid-tools')), 'content') as $toolUse) {
            self::assertTrue($executor->execute($toolUse, new PseudonymMap())['is_error'] ?? false);
        }
        self::assertSame([], array_values(array_filter($statements->messages, static fn(string $message): bool => str_contains($message, 'query') || str_contains($message, 'statement'))));
        $logged->close();
    }

    public function testToolListIsReadOnlyPlusDraftDocument(): void
    {
        self::assertSame(['get_obligations', 'query_invoices', 'get_fx_rate', 'verify_counterparty', 'render_chart', 'draft_document'], ClaudeTestTools::registry()->names());
        foreach (ClaudeTestTools::registry()->tools() as $tool) {
            self::assertTrue($tool['strict']);
            self::assertIsArray($tool['input_schema']);
            self::assertFalse($tool['input_schema']['additionalProperties']);
            self::assertNotEmpty($tool['input_schema']['required']);
            self::assertStringNotContainsString('sql', strtolower((string) json_encode($tool['input_schema'])));
        }
    }

    /** @return array<string,int> */
    private function writes(): array
    {
        $counts = [];
        foreach (['draft', 'approval', 'notification', 'invoice', 'setting', 'obligation'] as $table) {
            $counts[$table] = F1TestTools::count($this->connection, 'SELECT count(*) FROM ' . $table);
        }
        return $counts;
    }
}
