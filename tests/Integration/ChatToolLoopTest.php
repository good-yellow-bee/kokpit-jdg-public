<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\Claude\ClaudeError;
use App\Tests\Support\ChatTestTools;
use App\Tests\Support\ClaudeTestTools;
use App\Tests\Support\F1TestTools;
use Symfony\Component\Clock\MockClock;

/** chat-tool-loop: `tool_use` → `tool_result` z wynikiem nazwanego zapytania, thinking bez zmian, limit 8 iteracji. */
final class ChatToolLoopTest extends ClaudeTestCase
{
    public function testToolResultCarriesNamedQueryResultWithPseudonymsAndHistoryKeepsApiOrder(): void
    {
        $clock = new MockClock('2026-10-14T10:00:00Z');
        $ids = ChatTestTools::seed($this->connection, $clock);
        [$session] = ClaudeTestTools::start($this->connection, $clock);
        $requests = [];
        $mock = ClaudeTestTools::mock([ClaudeTestTools::response('messages-top-counterparties'), ClaudeTestTools::response('messages-end-turn')], $requests);

        $result = ClaudeTestTools::turn($this->connection, $clock, $mock)->ask($session, 'Kto był największym klientem w III kwartale?');

        self::assertNull($result->error);
        self::assertSame('W październiku masz jeden otwarty obowiązek (fictional).', $result->text);
        $bodies = ClaudeTestTools::bodies($requests);
        $second = ClaudeTestTools::messagesOf($bodies[1]);
        $toolResult = $second[2]['content'][0];
        self::assertSame(['type', 'tool_use_id', 'content'], array_keys($toolResult));
        self::assertSame('toolu_fictional_09', $toolResult['tool_use_id']);
        self::assertIsString($toolResult['content']);
        $rows = ClaudeTestTools::objects(ClaudeTestTools::decode($toolResult['content']), 'rows');
        self::assertSame(['K-01', 'K-02', 'K-03'], array_column($rows, 'counterparty'));
        self::assertSame(['10643.53', '1000.00', '300.33'], array_column($rows, 'net_pln'));
        foreach ([$ids['client_eu'], $ids['client_pl'], $ids['person'], 'Fictional Client DE'] as $hidden) {
            self::assertStringNotContainsString($hidden, $requests[1]['body']);
        }
        // Blok thinking z pierwszej odpowiedzi wraca bajt w bajt.
        $fixture = ClaudeTestTools::objects(ClaudeTestTools::decode(ClaudeTestTools::fixture('messages-top-counterparties')), 'content');
        self::assertSame($fixture[0], $second[1]['content'][0]);
        self::assertStringContainsString(json_encode($fixture[0], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $requests[1]['body']);
        // Historia w kolejności API, tylko dopisywana.
        $history = $this->connection->fetchAllAssociative('SELECT seq, role, stop_reason, jsonb_path_query_array(content, \'$[*].type\')::text AS types FROM chat_message WHERE chat_session_id = ? ORDER BY seq', [$session]);
        self::assertSame([
            ['seq' => 1, 'role' => 'user', 'stop_reason' => null, 'types' => '["text"]'],
            ['seq' => 2, 'role' => 'assistant', 'stop_reason' => 'tool_use', 'types' => '["thinking", "tool_use"]'],
            ['seq' => 3, 'role' => 'user', 'stop_reason' => null, 'types' => '["tool_result"]'],
            ['seq' => 4, 'role' => 'assistant', 'stop_reason' => 'end_turn', 'types' => '["thinking", "text"]'],
        ], $history);
        self::assertSame('{"K-01": "' . $ids['client_eu'] . '", "K-02": "' . $ids['client_pl'] . '", "K-03": "' . $ids['person'] . '"}', $this->connection->fetchOne('SELECT pseudonym_map::text FROM chat_session WHERE id = ?', [$session]));
    }

    public function testNineConsecutiveToolUsesEndTheTurnAfterEightRequests(): void
    {
        $clock = new MockClock('2026-10-14T10:00:00Z');
        ChatTestTools::seed($this->connection, $clock);
        [$session] = ClaudeTestTools::start($this->connection, $clock);
        $requests = [];
        $mock = ClaudeTestTools::mock(array_map(static fn(int $i): \Symfony\Component\HttpClient\Response\MockResponse => ClaudeTestTools::response('messages-tool-use'), range(1, 9)), $requests);

        $result = ClaudeTestTools::turn($this->connection, $clock, $mock)->ask($session, 'Fikcyjne pytanie bez końca.');

        self::assertSame(ClaudeError::IterationLimit, $result->error);
        self::assertSame(ClaudeTestTools::MAX_ITERATIONS, $result->requests);
        self::assertCount(ClaudeTestTools::MAX_ITERATIONS, $requests);
        self::assertNotSame('', $result->message());
        self::assertSame(8, F1TestTools::count($this->connection, "SELECT count(*) FROM chat_message WHERE chat_session_id = ? AND role = 'assistant'", [$session]));
    }
}
