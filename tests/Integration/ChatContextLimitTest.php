<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Chat\ChatContextGuard;
use App\Connector\Claude\ClaudeError;
use App\Tests\Support\ChatTestTools;
use App\Tests\Support\ClaudeTestTools;
use App\Tests\Support\F1TestTools;
use Symfony\Component\Clock\MockClock;

/** chat-context-limit: 80 tys. tokenów promptu → propozycja nowej rozmowy; 100 tys. → odmowa bez żądania i koniec pętli. */
final class ChatContextLimitTest extends ClaudeTestCase
{
    public function testThresholdsCountCachedInputTokens(): void
    {
        $guard = new ChatContextGuard(80000, 100000);
        $usage = static fn(int $input, int $read, int $write): array => ['input_tokens' => $input, 'output_tokens' => 10, 'cache_creation_input_tokens' => $write, 'cache_read_input_tokens' => $read];
        self::assertSame(80001, ChatContextGuard::promptTokens($usage(1, 80000, 0)));
        self::assertTrue($guard->warns(ChatContextGuard::promptTokens($usage(1, 80000, 0))));
        self::assertFalse($guard->exceeds(ChatContextGuard::promptTokens($usage(1, 80000, 0))));
        self::assertFalse($guard->warns(ChatContextGuard::promptTokens($usage(1, 70000, 9998))));
        self::assertTrue($guard->exceeds(ChatContextGuard::promptTokens($usage(1, 90000, 10000))));
    }

    public function testWarningRefusalAndInLoopEstimate(): void
    {
        $clock = new MockClock('2026-10-14T10:00:00Z');
        ChatTestTools::seed($this->connection, $clock);
        [$session] = ClaudeTestTools::start($this->connection, $clock);
        $requests = [];
        $mock = ClaudeTestTools::mock([ClaudeTestTools::response('messages-large-context'), ClaudeTestTools::response('messages-end-turn')], $requests);
        // Odpowiedź z 85 tys. tokenów promptu (60 tys. wejścia i 25 tys. z cache): odpowiedź i propozycja nowej rozmowy.
        $turn = ClaudeTestTools::turn($this->connection, $clock, $mock);
        $warned = $turn->ask($session, 'Fikcyjne pytanie w długiej rozmowie.');
        self::assertNull($warned->error);
        self::assertTrue($warned->suggestNewSession);
        self::assertNotSame('', $warned->message());

        // Ten sam rozmiar przy progu odmowy 85 tys.: kolejne pytanie odrzucone bez żądania i bez zapisu.
        $strict = ClaudeTestTools::turn($this->connection, $clock, $mock, ['context' => new ChatContextGuard(80000, 85000)]);
        $refused = $strict->ask($session, 'Fikcyjne pytanie ponad limit.');
        self::assertSame(ClaudeError::ContextLimit, $refused->error);
        self::assertSame(0, $refused->requests);
        self::assertTrue($refused->suggestNewSession);
        self::assertCount(1, $requests);
        self::assertSame(2, F1TestTools::count($this->connection, 'SELECT count(*) FROM chat_message WHERE chat_session_id = ?', [$session]));

        // W pętli: szacunek po wynikach narzędzi przekracza limit → koniec tury z komunikatem, bez kolejnego żądania.
        [$loopSession] = [ClaudeTestTools::sessions($this->connection, $clock)->start(F1TestTools::owner($this->connection, $clock))];
        $loopRequests = [];
        $loopMock = ClaudeTestTools::mock([ClaudeTestTools::response('messages-top-counterparties'), ClaudeTestTools::response('messages-end-turn')], $loopRequests);
        $loop = ClaudeTestTools::turn($this->connection, $clock, $loopMock, ['context' => new ChatContextGuard(1000, 2600)]);
        $ended = $loop->ask($loopSession, 'Fikcyjne pytanie o klientów.');
        self::assertSame(ClaudeError::ContextLimit, $ended->error);
        self::assertSame(1, $ended->requests);
        self::assertCount(1, $loopRequests);
        self::assertNotSame('', $ended->message());
    }
}
