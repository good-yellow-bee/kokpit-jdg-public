<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Chat\ChatPanel;
use App\Passkey\AppUser;
use App\Security\Vault;
use App\Tests\Support\ChatTestTools;
use App\Tests\Support\ClaudeTestTools;
use App\Tests\Support\F1TestTools;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Baza testów panelu czatu (F6.3, D-61): klient HTTP z sesją passkeya fikcyjnego właściciela, `ChatPanel` nad
 * `MockHttpClient` z ręcznie pisanymi odpowiedziami z `tests/Fixtures/Claude`, fikcyjny zbiór danych i wszystko
 * w jednej transakcji testu. Zero żądań sieciowych.
 */
abstract class ChatWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected Connection $db;
    protected MockClock $clock;
    protected string $owner;
    /** @var list<array{method:string,url:string,headers:list<string>,body:string}> */
    protected array $requests = [];
    protected ?ChatPanel $chat = null;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        $db->beginTransaction();
        $vault = self::getContainer()->get(Vault::class);
        self::assertInstanceOf(Vault::class, $vault);
        ClaudeTestTools::$vault = $vault;
        $this->clock = new MockClock('2026-10-14T10:00:00Z');
        ChatTestTools::seed($db, $this->clock);
        ClaudeTestTools::lawSnapshot($db, $this->clock);
        $this->owner = F1TestTools::owner($db, $this->clock);
        // Sesja panelu wymaga aktywnego passkeya właściciela (SessionLifetimeSubscriber); dane fikcyjne.
        if ($db->fetchOne('SELECT id FROM webauthn_credential WHERE app_user_id = ? AND revoked_at IS NULL', [$this->owner]) === false) {
            $stamp = $this->clock->now()->format('c');
            $db->insert('webauthn_credential', ['id' => (string) \Symfony\Component\Uid\UuidV7::generate($this->clock->now()), 'app_user_id' => $this->owner, 'credential_id' => 'fictional-chat-credential-id', 'public_key' => 'fictional-public-key', 'label' => 'Fikcyjny passkey', 'created_at' => $stamp, 'updated_at' => $stamp]);
        }
    }

    protected function tearDown(): void
    {
        ClaudeTestTools::$vault = null;
        while ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    /**
     * `ChatPanel` w kontenerze panelu z podanymi odpowiedziami Messages API i zalogowany właściciel; zwraca nową rozmowę.
     *
     * @param list<MockResponse> $responses
     * @param array{budget?:\App\Chat\ChatBudget,context?:\App\Chat\ChatContextGuard} $options
     */
    protected function panel(array $responses, array $options = [], bool $login = true): string
    {
        $this->requests = [];
        $panel = ClaudeTestTools::panel($this->db, $this->clock, ClaudeTestTools::mock($responses, $this->requests), $options);
        self::getContainer()->set(ChatPanel::class, $panel);
        $this->chat = $panel;
        if ($login) {
            $username = $this->db->fetchOne('SELECT username FROM app_user WHERE id = ?', [$this->owner]);
            $this->client->loginUser(new AppUser($this->owner, is_string($username) && $username !== '' ? $username : 'owner'));
        }
        return $panel->start($this->owner);
    }

    /** Kolejna rozmowa w tym samym panelu (kontener nie pozwala podmienić już użytej usługi). */
    protected function session(): string
    {
        return ($this->chat ?? throw new \LogicException('Najpierw panel().'))->start($this->owner);
    }

    /**
     * Widok `/chat` rozmowy i pola formularza z ramki: token CSRF i `seq`.
     *
     * @return array{_token:string,seq:string}
     */
    protected function form(string $session): array
    {
        $crawler = $this->client->request('GET', '/chat?session=' . $session);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('turbo-frame#chat-turn form#chat-form');
        self::assertCount(1, $form, 'Brak formularza pytania w ramce chat-turn.');
        return ['_token' => (string) $form->filter('input[name=_token]')->attr('value'), 'seq' => (string) $form->filter('input[name=seq]')->attr('value')];
    }

    /**
     * Wysłanie pytania z ramki Turbo; zwraca ramkę z odpowiedzi.
     *
     * @param array{_token:string,seq:string} $form
     */
    protected function ask(string $session, array $form, string $question, bool $draft = false): Crawler
    {
        $fields = $form + ['question' => $question];
        if ($draft) {
            $fields['draft'] = '1';
        }
        $crawler = $this->client->request('POST', '/chat/' . $session . '/ask', $fields, [], ['HTTP_TURBO_FRAME' => ChatPanel::FRAME]);
        self::assertResponseIsSuccessful();
        $frame = $crawler->filter('turbo-frame#chat-turn');
        self::assertCount(1, $frame, 'Odpowiedź bez ramki chat-turn.');
        return $frame;
    }

    /** Ramka bez losowej wartości tokenu CSRF: porównanie dwóch odpowiedzi. */
    protected static function normalized(Crawler $frame): string
    {
        return (string) preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value=""', $frame->outerHtml());
    }

    /** @param list<mixed> $params */
    protected function rows(string $sql, array $params = []): int
    {
        $value = $this->db->fetchOne($sql, $params);
        return is_int($value) ? $value : (int) (is_string($value) ? $value : 0);
    }
}
