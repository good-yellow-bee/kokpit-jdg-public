<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\ClaudeTestTools;
use App\Tests\Support\EvidenceArtefacts;

/**
 * chat-turbo-frame: `/chat` za passkeyem, pytanie w ramce Turbo `chat-turn` z całą odpowiedzią i etykietą
 * „asystent AI”; bez tokenu CSRF i bez sesji odmowa; zero wiadomości Messenger, bez transportu `chat` i Mercure.
 */
final class ChatTurboFrameTest extends ChatWebTestCase
{
    public function testFrameCarriesWholeAnswerWithAiLabelSynchronouslyWithoutMessenger(): void
    {
        $session = $this->panel([ClaudeTestTools::response('messages-tool-use'), ClaudeTestTools::response('messages-end-turn')]);
        $queued = $this->rows('SELECT count(*) FROM messenger_messages');
        $form = $this->form($session);
        // Etykieta „asystent AI” od pierwszej wiadomości: widoczna już w pustej rozmowie.
        self::assertSelectorTextContains('turbo-frame#chat-turn #chat-ai-label', 'asystent AI');
        self::assertSame('1', $form['seq']);
        self::assertSelectorExists('#chat-form input[type=checkbox][name=draft]');
        self::assertSelectorTextContains('#chat-form', 'Szkic');
        self::assertSelectorExists('#chat-form .chat-working');

        $frame = $this->ask($session, $form, 'Jakie obowiązki mam w październiku?');

        // Żądanie z ramki dostaje samą ramkę z całą odpowiedzią pętli narzędzi.
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith('<turbo-frame id="chat-turn"', ltrim($html));
        self::assertStringNotContainsString('<html', $html);
        self::assertStringContainsString('asystent AI', $frame->filter('#chat-ai-label')->text());
        $turn = $frame->filter('#chat-transcript li.chat-turn');
        self::assertCount(1, $turn);
        self::assertSame('1', $turn->attr('data-seq'));
        self::assertStringContainsString('Jakie obowiązki mam w październiku?', $turn->filter('.chat-question')->text());
        $answer = $turn->filter('.chat-answer')->text();
        self::assertStringContainsString('asystent AI', $answer);
        self::assertStringContainsString('Sprawdzę obowiązki w październiku (fictional).', $answer);
        self::assertStringContainsString('W październiku masz jeden otwarty obowiązek (fictional).', $answer);
        self::assertCount(0, $turn->filter('.chat-turn-status'));
        self::assertCount(0, $frame->filter('#chat-notice'));
        self::assertSame('5', $frame->filter('#chat-form input[name=seq]')->attr('value'));
        self::assertCount(2, $this->requests);
        self::assertSame(4, $this->rows('SELECT count(*) FROM chat_message WHERE chat_session_id = ?', [$session]));
        self::assertSame('Jakie obowiązki mam w październiku?', $this->db->fetchOne('SELECT title FROM chat_session WHERE id = ?', [$session]));

        // Synchronicznie: zero wiadomości Messenger, brak transportu `chat` i Mercure w kontenerze.
        self::assertSame($queued, $this->rows('SELECT count(*) FROM messenger_messages'));
        $container = self::getContainer();
        self::assertTrue($container->has('messenger.transport.reads'), 'Kontrola: transport reads jest widoczny w kontenerze testu.');
        self::assertFalse($container->has('messenger.transport.chat'));
        $bundles = array_keys(self::$kernel?->getBundles() ?? []);
        self::assertNotContains('MercureBundle', $bundles);
        self::assertFalse(class_exists('Symfony\Component\Mercure\HubInterface'));
        $root = dirname(__DIR__, 2);
        $sources = array_merge(glob($root . '/src/Chat/*.php') ?: [], [$root . '/src/Controller/ChatController.php']);
        foreach ($sources as $source) {
            $code = (string) file_get_contents($source);
            self::assertStringNotContainsString('Symfony\Component\Messenger', $code, $source);
            self::assertStringNotContainsString('Symfony\Component\Mercure', $code, $source);
            self::assertStringNotContainsString('MessageBusInterface', $code, $source);
        }

        // Zwykłe wysłanie formularza (bez nagłówka Turbo-Frame) daje całą stronę z tą samą ramką; ta sama `seq` bez żądania.
        $page = $this->client->request('POST', '/chat/' . $session . '/ask', ['_token' => $form['_token'], 'seq' => '1', 'question' => 'Jakie obowiązki mam w październiku?']);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('html turbo-frame#chat-turn'));
        self::assertCount(2, $this->requests);
        EvidenceArtefacts::write('screens/chat-turbo-frame.html', self::normalized($frame));
    }

    public function testPostWithoutCsrfTokenIsRefusedWithoutRequest(): void
    {
        $session = $this->panel([ClaudeTestTools::response('messages-end-turn')]);
        $this->form($session);
        $this->client->request('POST', '/chat/' . $session . '/ask', ['seq' => '1', 'question' => 'Fikcyjne pytanie bez tokenu.'], [], ['HTTP_TURBO_FRAME' => 'chat-turn']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/chat/' . $session . '/ask', ['_token' => 'fikcyjny-zly-token', 'seq' => '1', 'question' => 'Fikcyjne pytanie ze złym tokenem.'], [], ['HTTP_TURBO_FRAME' => 'chat-turn']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->requests);
        self::assertSame(0, $this->rows('SELECT count(*) FROM chat_message WHERE chat_session_id = ?', [$session]));
    }

    public function testPostWithoutPasskeySessionIsRefusedWithoutRequest(): void
    {
        $session = $this->panel([ClaudeTestTools::response('messages-end-turn')], login: false);
        $this->client->request('GET', '/chat');
        self::assertResponseRedirects('/login');
        $this->client->request('POST', '/chat/' . $session . '/ask', ['_token' => 'fikcyjny-token', 'seq' => '1', 'question' => 'Fikcyjne pytanie bez sesji.'], [], ['HTTP_TURBO_FRAME' => 'chat-turn']);
        self::assertResponseRedirects('/login');
        self::assertSame([], $this->requests);
        self::assertSame(0, $this->rows('SELECT count(*) FROM chat_message WHERE chat_session_id = ?', [$session]));
    }
}
