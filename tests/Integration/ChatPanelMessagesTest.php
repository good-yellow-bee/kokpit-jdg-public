<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\Claude\ClaudeError;
use App\Tests\Support\ClaudeTestTools;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * chat-panel-messages: `refusal`, `max_tokens`, limit czasu, błąd API, 429, budżet i 100 tys. tokenów dają w ramce
 * `chat-turn` własny komunikat; zero ponownych żądań (bez automatycznego ponowienia).
 */
final class ChatPanelMessagesTest extends ChatWebTestCase
{
    public function testRefusalMaxTokensTimeoutAndApiErrorEachGiveOwnMessageWithOneRequest(): void
    {
        $cases = [
            'refusal' => ClaudeError::Refusal,
            'max_tokens' => ClaudeError::MaxTokens,
            'timeout' => ClaudeError::Timeout,
            'api_error' => ClaudeError::ApiError,
        ];
        $session = $this->panel([
            ClaudeTestTools::response('messages-refusal'),
            ClaudeTestTools::response('messages-max-tokens'),
            ClaudeTestTools::timeout(),
            new MockResponse('{"type":"error","error":{"type":"api_error","message":"fictional"}}', ['http_code' => 500]),
        ]);
        $messages = [];
        $requests = 0;
        foreach ($cases as $case => $error) {
            $frame = $this->ask($session, $this->form($session), 'Fikcyjne pytanie: ' . $case . '.');
            $status = $frame->filter('#chat-turn-1 .chat-turn-status');
            self::assertCount(1, $status, $case);
            self::assertSame($error->message(), $status->text(), $case);
            self::assertCount(++$requests, $this->requests, $case . ': bez ponowienia');
            $messages[] = $status->text();
            $session = $this->session();
        }
        self::assertCount(count($cases), array_unique($messages));
    }

    public function testRateLimitShowsWaitTimeAndRefusesNextQuestionBeforeApi(): void
    {
        $session = $this->panel([ClaudeTestTools::response('messages-rate-limit', 429, ['retry-after' => '120']), ClaudeTestTools::response('messages-end-turn')]);
        $frame = $this->ask($session, $this->form($session), 'Fikcyjne pytanie przy limicie.');
        $expected = ClaudeError::RateLimited->message() . ' Ponów po 2026-10-14 12:02.';
        self::assertSame($expected, $frame->filter('#chat-turn-1 .chat-turn-status')->text());
        self::assertCount(1, $this->requests);

        // Kolejne pytanie przed `blocked_until`: odmowa przed API, komunikat z czasem oczekiwania nad formularzem.
        $next = $this->ask($session, $this->form($session), 'Kolejne fikcyjne pytanie przed końcem blokady.');
        self::assertSame($expected, $next->filter('#chat-notice')->text());
        self::assertCount(1, $this->requests);
    }

    public function testBudgetExceededRefusesBeforeApi(): void
    {
        // Jedna odpowiedź `messages-end-turn` kosztuje 0.000090 USD; budżet dzienny 0.000050 wyczerpany po niej.
        $session = $this->panel([ClaudeTestTools::response('messages-end-turn'), ClaudeTestTools::response('messages-end-turn')], ['budget' => ClaudeTestTools::budget($this->db, $this->clock, '0.000050', '10.00')]);
        $first = $this->ask($session, $this->form($session), 'Fikcyjne pierwsze pytanie.');
        self::assertCount(0, $first->filter('#chat-turn-1 .chat-turn-status'));
        $refused = $this->ask($session, $this->form($session), 'Fikcyjne pytanie ponad budżet.');
        self::assertSame(ClaudeError::BudgetExceeded->message(), $refused->filter('#chat-notice')->text());
        self::assertCount(1, $this->requests);
    }

    public function testContextOver100kTokensRefusesNextQuestionAndProposesNewSession(): void
    {
        // Odpowiedź z promptem 100 001 tokenów (wejście z cache): propozycja nowej rozmowy, kolejne pytanie odrzucone bez żądania.
        $session = $this->panel([ClaudeTestTools::response('messages-context-limit'), ClaudeTestTools::response('messages-end-turn')]);
        $first = $this->ask($session, $this->form($session), 'Fikcyjne pytanie w długiej rozmowie.');
        self::assertCount(1, $first->filter('#chat-suggest-new'));
        $refused = $this->ask($session, $this->form($session), 'Fikcyjne pytanie ponad limit rozmiaru.');
        self::assertSame(ClaudeError::ContextLimit->message(), $refused->filter('#chat-notice')->text());
        self::assertCount(1, $refused->filter('#chat-suggest-new'));
        self::assertCount(1, $this->requests);
        self::assertSame(2, $this->rows('SELECT count(*) FROM chat_message WHERE chat_session_id = ?', [$session]));

        // „Nowa rozmowa” z propozycji otwiera rozmowę, a „Zamknij” ustawia `closed_at`.
        $token = (string) $refused->filter('#chat-suggest-new input[name=_token]')->attr('value');
        $sessions = $this->rows('SELECT count(*) FROM chat_session WHERE app_user_id = ?', [$this->owner]);
        $this->client->request('POST', '/chat/new', ['_token' => $token]);
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('~^/chat\?session=[0-9a-f-]{36}$~', $location);
        $this->client->request('POST', '/chat/' . $session . '/close', ['_token' => $token]);
        self::assertResponseRedirects('/chat');
        self::assertNotFalse($this->db->fetchOne('SELECT closed_at FROM chat_session WHERE id = ? AND closed_at IS NOT NULL', [$session]));
        $closed = $this->client->request('GET', '/chat?session=' . $session);
        self::assertCount(0, $closed->filter('#chat-form'));
        self::assertSame($sessions + 1, $this->rows('SELECT count(*) FROM chat_session WHERE app_user_id = ?', [$this->owner]));
        self::assertCount(1, $this->requests);
    }
}
