<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\ClaudeTestTools;

/**
 * chat-seq-idempotent: dwa wysłania tej samej `seq` dają jedno wywołanie API, jedną odpowiedź w `chat_message`
 * i tę samą ramkę. Po błędzie API ponowienie tej samej `seq` nie woła API; nieaktualna `seq` też nie.
 */
final class ChatSeqIdempotentTest extends ChatWebTestCase
{
    public function testSameSeqTwiceGivesOneApiCallOneAnswerAndTheSameFrame(): void
    {
        $session = $this->panel([ClaudeTestTools::response('messages-end-turn'), ClaudeTestTools::response('messages-end-turn')]);
        $form = $this->form($session);

        $first = self::normalized($this->ask($session, $form, 'Fikcyjne pytanie o obowiązki.'));
        $second = self::normalized($this->ask($session, $form, 'Fikcyjne pytanie o obowiązki.'));

        self::assertCount(1, $this->requests);
        self::assertSame($first, $second);
        self::assertSame(1, $this->rows("SELECT count(*) FROM chat_message WHERE chat_session_id = ? AND role = 'user'", [$session]));
        self::assertSame(1, $this->rows("SELECT count(*) FROM chat_message WHERE chat_session_id = ? AND role = 'assistant'", [$session]));
        self::assertStringContainsString('W październiku masz jeden otwarty obowiązek (fictional).', $second);

        // Inna treść z tą samą `seq` (np. edycja i ponowne wysłanie starego formularza) też nie woła API.
        $this->ask($session, $form, 'Inne fikcyjne pytanie z tą samą seq.');
        self::assertCount(1, $this->requests);
        self::assertSame(2, $this->rows('SELECT count(*) FROM chat_message WHERE chat_session_id = ?', [$session]));
    }

    public function testResubmitAfterApiErrorAndStaleSeqDoNotCallApi(): void
    {
        $session = $this->panel([ClaudeTestTools::timeout(), ClaudeTestTools::response('messages-end-turn')]);
        $form = $this->form($session);

        $failed = $this->ask($session, $form, 'Fikcyjne pytanie z limitem czasu.');
        self::assertStringContainsString('Przekroczony limit czasu odpowiedzi', $failed->filter('#chat-turn-1 .chat-turn-status')->text());
        $again = $this->ask($session, $form, 'Fikcyjne pytanie z limitem czasu.');
        self::assertStringContainsString('Brak zapisanej odpowiedzi na to pytanie', $again->filter('#chat-turn-1 .chat-turn-status')->text());
        self::assertCount(1, $this->requests);

        // `seq` spoza historii (formularz sprzed zmian w innej karcie) → komunikat bez wywołania API.
        $stale = $this->ask($session, ['_token' => $form['_token'], 'seq' => '7'], 'Fikcyjne pytanie z nieaktualną seq.');
        self::assertStringContainsString('Rozmowa zmieniła się od wczytania formularza', $stale->filter('#chat-notice')->text());
        self::assertCount(1, $this->requests);
        self::assertSame(1, $this->rows('SELECT count(*) FROM chat_message WHERE chat_session_id = ?', [$session]));
    }
}
