<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Audit\AuditLog;
use App\Calendar\PolishCalendar;
use App\Chat\ChatBudget;
use App\Chat\ChatChart;
use App\Chat\ChatContextGuard;
use App\Chat\ChatPanel;
use App\Chat\ChatQueryRunner;
use App\Chat\ChatRedaction;
use App\Chat\ChatSessions;
use App\Chat\ChatToolExecutor;
use App\Chat\ChatToolRegistry;
use App\Chat\ChatTurn;
use App\Chat\DraftDocumentTool;
use App\Connector\Claude\ChatRequestProfile;
use App\Connector\Claude\ClaudeClientFactory;
use App\Connector\Claude\ClaudeMessages;
use App\Rate\RateCounter;
use App\Security\Redactor;
use App\Security\SecretStore;
use App\Security\Vault;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Konektor `claude` i pętla czatu w testach (F6.2): ręcznie pisane, fikcyjne odpowiedzi z `tests/Fixtures/Claude`
 * odtwarzane przez `MockHttpClient` za tym samym `HostAllowlist` i `ConnectorHttpClient` co w produkcji.
 * Klucz API to fikcyjna wartość sejfu testowego (`CLAUDE_TEST_API_KEY`). Zero żądań sieciowych.
 */
final class ClaudeTestTools
{
    public const PROMPT = 'Fikcyjny prompt systemowy testu czatu (fictional).';
    public const BASE_URL = 'https://api.anthropic.com';
    public const TIMEOUT_S = 90;
    public const MAX_TOKENS = 8000;
    public const MAX_ITERATIONS = 8;
    public const PRICING = ['threshold_tokens' => 100000, 'standard' => ['input' => '0.10', 'output' => '0.50', 'cache_read' => '0.01', 'cache_write' => '0.125'], 'long' => ['input' => '0.50', 'output' => '2.50', 'cache_read' => '0.05', 'cache_write' => '0.625']];
    public const SNAPSHOT = ['items' => [['name' => 'Stawka VAT podstawowa (fictional)', 'value' => '23%', 'legal_source' => 'art. 41 ust. 1 ustawy o VAT']], 'note' => 'fikcyjny snapshot testu'];

    public static function fixture(string $name): string
    {
        $content = file_get_contents(dirname(__DIR__) . '/Fixtures/Claude/' . $name . '.json');
        if ($content === false) {
            throw new \RuntimeException('Brak fixture Claude.');
        }
        return $content;
    }

    /** @param array<string,string> $headers */
    public static function response(string $name, int $status = 200, array $headers = []): MockResponse
    {
        return new MockResponse(self::fixture($name), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json', 'request-id' => 'req_fictional_' . $name] + $headers]);
    }

    /** Odpowiedź bez bajtów: `MockHttpClient` traktuje pusty fragment jako przekroczony limit czasu. */
    public static function timeout(): MockResponse
    {
        return new MockResponse((static function (): \Generator {
            yield '';
        })());
    }

    /**
     * Odtwarza kolejne odpowiedzi i zapisuje metodę, adres, nagłówki i ciało każdego żądania.
     *
     * @param list<MockResponse> $responses
     * @param list<array{method:string,url:string,headers:list<string>,body:string}> $requests
     */
    public static function mock(array $responses, array &$requests): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options) use (&$responses, &$requests): MockResponse {
            $body = $options['body'] ?? '';
            $headers = $options['headers'] ?? [];
            $requests[] = ['method' => $method, 'url' => $url, 'headers' => is_array($headers) ? array_values(array_filter($headers, is_string(...))) : [], 'body' => is_string($body) ? $body : ''];
            $next = array_shift($responses);
            if (!$next instanceof MockResponse) {
                throw new \LogicException('Brak kolejnej fikcyjnej odpowiedzi Claude.');
            }
            return $next;
        }, self::BASE_URL);
    }

    /**
     * @param list<array{method:string,url:string,headers:list<string>,body:string}> $requests
     * @return list<array<string,mixed>>
     */
    public static function bodies(array $requests): array
    {
        return array_map(static fn(array $request): array => self::decode($request['body']), $requests);
    }

    /** @return array<string,mixed> obiekt JSON (ciało żądania, fixture albo treść `tool_result`) */
    public static function decode(string $json): array
    {
        $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \LogicException('JSON nie jest obiektem.');
        }
        $out = [];
        foreach ($decoded as $key => $value) {
            $out[(string) $key] = $value;
        }
        return $out;
    }

    /** @return list<mixed> lista JSON (np. atrybut `data-chart-values` ramki panelu) */
    public static function decodeList(string $json): array
    {
        $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new \LogicException('JSON nie jest listą.');
        }
        return $decoded;
    }

    /** @return list<array<string,mixed>> lista obiektów pod kluczem (`system`, `tools`, `content`, `rows`) */
    public static function objects(mixed $data, string $key): array
    {
        $list = is_array($data) ? ($data[$key] ?? null) : null;
        if (!is_array($list) || !array_is_list($list)) {
            throw new \LogicException(sprintf('Brak listy %s.', $key));
        }
        $out = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                throw new \LogicException(sprintf('Element %s nie jest obiektem.', $key));
            }
            $object = [];
            foreach ($item as $field => $value) {
                $object[(string) $field] = $value;
            }
            $out[] = $object;
        }
        return $out;
    }

    /**
     * Wiadomości żądania: rola i bloki treści.
     *
     * @param array<string,mixed> $body
     * @return list<array{role:string,content:list<array<string,mixed>>}>
     */
    public static function messagesOf(array $body): array
    {
        return array_map(static fn(array $message): array => ['role' => is_string($message['role'] ?? null) ? $message['role'] : throw new \LogicException('Brak roli.'), 'content' => self::objects($message, 'content')], self::objects($body, 'messages'));
    }

    /** Sejf testowy z kontenera; ustawia go `ClaudeTestCase::setUp`. */
    public static ?Vault $vault = null;

    public static function vault(): Vault
    {
        return self::$vault ?? throw new \LogicException('Brak sejfu testowego: test dziedziczy ClaudeTestCase.');
    }

    public static function messages(Connection $db, MockClock $clock, MockHttpClient $mock, bool $enabled = true, ?Redactor $redactor = null): ClaudeMessages
    {
        $redactor ??= new Redactor();
        $configuration = ConnectorTestTools::configuration($enabled);
        $secrets = new SecretStore(self::vault(), $db, new LockFactory(new InMemoryStore()), $clock, $redactor, new AuditLog($db, $clock, $redactor), 'test');
        $factory = new ClaudeClientFactory(ConnectorTestTools::http($db, $clock, $mock, $redactor, $enabled), $secrets, $configuration, self::BASE_URL, self::TIMEOUT_S);
        return new ClaudeMessages($factory, self::rates($db, $clock), $configuration);
    }

    public static function rates(Connection $db, MockClock $clock): RateCounter
    {
        return new RateCounter($db, $clock, new BusinessDate($clock), []);
    }

    public static function profile(string $effort = 'low'): ChatRequestProfile
    {
        return new ChatRequestProfile(ChatRequestProfile::MODEL, $effort, self::MAX_TOKENS);
    }

    /** @param list<string> $phases */
    public static function registry(array $phases = ChatToolRegistry::PHASES): ChatToolRegistry
    {
        return new ChatToolRegistry($phases, ChatTestTools::CURRENCIES);
    }

    /** @param array<string,mixed> $value */
    public static function lawSnapshot(Connection $db, MockClock $clock, string $validFrom = '2026-01-01', array $value = self::SNAPSHOT): void
    {
        $redactor = new Redactor();
        (new SettingStore($db, $clock, new BusinessDate($clock), new AuditLog($db, $clock, $redactor), ConnectorTestTools::configuration(false)))->set(SettingKeys::CHAT_LAW_SNAPSHOT, $value, $validFrom, null, 'fictional');
    }

    public static function budget(Connection $db, MockClock $clock, string $daily = '1.00', string $monthly = '10.00', string $ratio = '0.80'): ChatBudget
    {
        return new ChatBudget($db, $clock, new BusinessDate($clock), $daily, $monthly, $ratio, self::PRICING);
    }

    public static function sessions(Connection $db, MockClock $clock, ?ChatRequestProfile $profile = null, ?ChatToolRegistry $registry = null, string $prompt = self::PROMPT): ChatSessions
    {
        return new ChatSessions($db, $clock, new BusinessDate($clock), $profile ?? self::profile(), $registry ?? self::registry(), $prompt);
    }

    public static function redaction(Connection $db): ChatRedaction
    {
        return new ChatRedaction($db, ConnectorTestTools::configuration(false), new Redactor());
    }

    public static function executor(Connection $db, MockClock $clock, ?ChatToolRegistry $registry = null, ?DraftDocumentTool $drafts = null): ChatToolExecutor
    {
        return new ChatToolExecutor(new ChatQueryRunner($db, ConnectorTestTools::configuration(false), new BusinessDate($clock), new PolishCalendar(), 200, ChatTestTools::CURRENCIES), $registry ?? self::registry(), self::redaction($db), $drafts);
    }

    /**
     * Fixture z podstawieniami znaczników `{{NAZWA}}`: wartości, których skaner nie wpuszcza jako literałów (IBAN, NRB),
     * powstają w kodzie testu.
     *
     * @param array<string,string> $values
     */
    public static function responseWith(string $name, array $values): MockResponse
    {
        $replace = [];
        foreach ($values as $key => $value) {
            $replace['{{' . $key . '}}'] = $value;
        }
        return new MockResponse(strtr(self::fixture($name), $replace), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json', 'request-id' => 'req_fictional_' . $name]]);
    }

    /**
     * Pełna tura czatu nad atrapą HTTP; opcje: `budget`, `context` (ChatContextGuard), `iterations`, `enabled`, `registry`,
     * `drafts` (narzędzie `draft_document` z F6.4; bez niego szkic zawsze odmawia).
     *
     * @param array{budget?:ChatBudget,context?:ChatContextGuard,iterations?:int,enabled?:bool,registry?:ChatToolRegistry,redactor?:Redactor,drafts?:DraftDocumentTool} $options
     */
    public static function turn(Connection $db, MockClock $clock, MockHttpClient $mock, array $options = []): ChatTurn
    {
        $registry = $options['registry'] ?? self::registry();
        $profile = self::profile();
        return new ChatTurn(
            $db,
            $clock,
            self::sessions($db, $clock, $profile, $registry),
            $profile,
            $registry,
            self::executor($db, $clock, $registry, $options['drafts'] ?? null),
            self::redaction($db),
            $options['budget'] ?? self::budget($db, $clock),
            $options['context'] ?? new ChatContextGuard(80000, 100000),
            self::messages($db, $clock, $mock, $options['enabled'] ?? true, $options['redactor'] ?? null),
            self::rates($db, $clock),
            ConnectorTestTools::configuration($options['enabled'] ?? true),
            $options['iterations'] ?? self::MAX_ITERATIONS,
        );
    }

    /**
     * Rozmowa ze snapshotem prawa i fikcyjnym właścicielem.
     *
     * @return array{0:string,1:string} id rozmowy i id właściciela
     */
    public static function start(Connection $db, MockClock $clock): array
    {
        self::lawSnapshot($db, $clock);
        $owner = F1TestTools::owner($db, $clock);
        return [self::sessions($db, $clock)->start($owner), $owner];
    }

    public const SESSION_LIST_LIMIT = 50;

    /**
     * Panel czatu (F6.3) nad tą samą turą co `turn`: wykresy przez `ChatQueryRunner` na połączeniu testu (transakcja
     * testu z fikcyjnym zbiorem), blokada w pamięci. Opcje jak w `turn`.
     *
     * @param array{budget?:ChatBudget,context?:ChatContextGuard,iterations?:int,enabled?:bool,registry?:ChatToolRegistry,redactor?:Redactor,drafts?:DraftDocumentTool} $options
     */
    public static function panel(Connection $db, MockClock $clock, MockHttpClient $mock, array $options = []): ChatPanel
    {
        return new ChatPanel(
            $db,
            self::sessions($db, $clock, null, $options['registry'] ?? null),
            self::turn($db, $clock, $mock, $options),
            new ChatChart(ChatTestTools::runner($db, $clock), $db),
            $options['context'] ?? new ChatContextGuard(80000, 100000),
            new LockFactory(new InMemoryStore()),
            $clock,
            self::SESSION_LIST_LIMIT,
        );
    }
}
