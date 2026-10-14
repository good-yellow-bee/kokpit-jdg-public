<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\Claude\ClaudeError;
use App\Tests\Support\ChatTestTools;
use App\Tests\Support\ClaudeTestTools;
use App\Tests\Support\F1TestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;

/** chat-interrupted-turn: otwarte `tool_use` po błędzie API albo limicie czasu dostaje `tool_result` z `is_error`. */
final class ChatInterruptedTurnTest extends ClaudeTestCase
{
    public function testNextQuestionClosesOpenToolUsesWithErrorResults(): void
    {
        $clock = new MockClock('2026-10-14T10:00:00Z');
        ChatTestTools::seed($this->connection, $clock);
        $failures = ['api_error' => new MockResponse('{"type":"error","error":{"type":"api_error","message":"fictional"}}', ['http_code' => 500]), 'timeout' => ClaudeTestTools::timeout()];
        $expected = ['api_error' => ClaudeError::ApiError, 'timeout' => ClaudeError::Timeout];
        ClaudeTestTools::lawSnapshot($this->connection, $clock);
        foreach ($failures as $case => $failure) {
            $session = ClaudeTestTools::sessions($this->connection, $clock)->start(F1TestTools::owner($this->connection, $clock));
            $requests = [];
            $mock = ClaudeTestTools::mock([ClaudeTestTools::response('messages-two-tools'), $failure, ClaudeTestTools::response('messages-end-turn')], $requests);
            $turn = ClaudeTestTools::turn($this->connection, $clock, $mock);

            $first = $turn->ask($session, 'Fikcyjne pytanie o sprzedaż.');
            self::assertSame($expected[$case], $first->error);
            self::assertSame(2, $first->requests);
            // `tool_use` zapisane, wynik narzędzia nie: tura przerwana.
            self::assertSame(['user', 'assistant'], $this->connection->fetchFirstColumn('SELECT role FROM chat_message WHERE chat_session_id = ? ORDER BY seq', [$session]));

            $second = $turn->ask($session, 'Kolejne fikcyjne pytanie.');
            self::assertNull($second->error);
            self::assertCount(3, $requests);
            $messages = ClaudeTestTools::messagesOf(ClaudeTestTools::bodies($requests)[2]);
            $last = end($messages);
            self::assertIsArray($last);
            self::assertSame('user', $last['role']);
            self::assertSame(['tool_result', 'tool_result', 'text'], array_column($last['content'], 'type'));
            self::assertSame(['toolu_fictional_02', 'toolu_fictional_03'], array_slice(array_column($last['content'], 'tool_use_id'), 0, 2));
            self::assertSame([true, true], array_slice(array_column($last['content'], 'is_error'), 0, 2));
            self::assertSame('Kolejne fikcyjne pytanie.', $last['content'][2]['text']);
        }
    }
}
