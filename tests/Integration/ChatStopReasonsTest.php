<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\Claude\ChatRequestProfile;
use App\Connector\Claude\ClaudeError;
use App\Tests\Support\ChatTestTools;
use App\Tests\Support\ClaudeTestTools;
use App\Tests\Support\F1TestTools;
use Symfony\Component\Clock\MockClock;

/** chat-stop-reasons: każda gałąź `stop_reason` kończy turę komunikatem po jednym żądaniu, bez ponowień i zmiany modelu. */
final class ChatStopReasonsTest extends ClaudeTestCase
{
    public function testEveryStopReasonEndsTheTurnAfterOneRequest(): void
    {
        $clock = new MockClock('2026-10-14T10:00:00Z');
        ChatTestTools::seed($this->connection, $clock);
        ClaudeTestTools::lawSnapshot($this->connection, $clock);
        $cases = [
            'messages-end-turn' => [null, null],
            'messages-max-tokens' => [ClaudeError::MaxTokens, null],
            'messages-refusal' => [ClaudeError::Refusal, 'cyber'],
            'messages-refusal-no-category' => [ClaudeError::Refusal, null],
            'messages-refusal-other-category' => [ClaudeError::Refusal, 'fictional_new_category'],
            'messages-unknown-stop' => [ClaudeError::InvalidResponse, null],
        ];
        foreach ($cases as $fixture => [$error, $category]) {
            $session = ClaudeTestTools::sessions($this->connection, $clock)->start(F1TestTools::owner($this->connection, $clock));
            $requests = [];
            $mock = ClaudeTestTools::mock([ClaudeTestTools::response($fixture), ClaudeTestTools::response($fixture)], $requests);
            $result = ClaudeTestTools::turn($this->connection, $clock, $mock)->ask($session, 'Fikcyjne pytanie ' . $fixture . '.');
            self::assertSame($error, $result->error, $fixture);
            self::assertSame($category, $result->refusalCategory, $fixture);
            self::assertSame(1, $result->requests, $fixture);
            self::assertCount(1, $requests, $fixture);
            self::assertSame(ChatRequestProfile::MODEL, ClaudeTestTools::bodies($requests)[0]['model']);
            if ($error !== null) {
                self::assertNotSame('', $result->message(), $fixture);
            } else {
                self::assertNotSame('', $result->text);
            }
            self::assertSame(2, F1TestTools::count($this->connection, 'SELECT count(*) FROM chat_message WHERE chat_session_id = ?', [$session]));
        }
        // Odmowa z kategorią spoza listy dokumentacji też kończy turę bez ponowienia.
        $stored = $this->connection->fetchFirstColumn("SELECT stop_reason FROM chat_message WHERE role = 'assistant' ORDER BY id");
        self::assertSame(['end_turn', 'max_tokens', 'refusal', 'refusal', 'refusal', 'pause_turn'], $stored);
    }
}
