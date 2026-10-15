<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Odtwarzanie ręcznie pisanych, fikcyjnych nagrań KSeF i Latarni (docs/testing.md, „Nagrania i odtwarzanie”):
 * kolejka odpowiedzi per metoda i ścieżka, nigdy po treści. Żądanie spoza nagrania kończy test błędem, więc nic nie
 * wychodzi do sieci. Treść odpowiedzi może mieć zmienne `{{nazwa}}`; `sent_hash` to `invoiceHash` ostatniej wysyłki.
 *
 * Tryb live (`@group live`, F5.3, F5.6, F3.5; D-68): z `$live` żądanie po `HostAllowlist` i `connector_call` idzie
 * prawdziwym klientem na host testowy, a odpowiedź trafia do `$recorded` w formacie nagrania (do oczyszczenia
 * `Scrubber` i zapisu przez `LiveRecording`).
 */
final class KsefRecordings
{
    public const DIRECTORY = __DIR__ . '/../Fixtures';

    /** @var array<string,string> */
    public array $vars = [];
    /** @var list<array{method:string,path:string,query:string,body:string}> */
    public array $requests = [];
    /** @var list<array<string,int|string>> odpowiedzi trybu live: `method`, `path`, `status`, `body_text` (albo `error`) */
    public array $recorded = [];
    /** @var array<string,list<array<string,mixed>>> */
    private array $queues = [];

    public function __construct(private readonly ?HttpClientInterface $live = null) {}

    /** Dokłada interakcje z pliku `tests/Fixtures/<Connector>/<scenariusz>.json` na koniec kolejek. */
    public function load(string $file): self
    {
        $raw = file_get_contents(self::DIRECTORY . '/' . $file);
        $data = json_decode(is_string($raw) ? $raw : '', true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['fictional'] ?? null) !== true || !is_array($data['interactions'] ?? null)) {
            throw new \LogicException('Nagranie musi być fikcyjne i mieć interakcje: ' . $file);
        }
        foreach ($data['interactions'] as $interaction) {
            if (!is_array($interaction) || !is_string($interaction['method'] ?? null) || !is_string($interaction['path'] ?? null)) {
                throw new \LogicException('Niepoprawna interakcja w ' . $file);
            }
            $this->queues[$interaction['method'] . ' ' . $interaction['path']][] = array_filter($interaction, static fn(mixed $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
        }
        return $this;
    }

    public function client(): MockHttpClient
    {
        return new MockHttpClient(fn(string $method, string $url, array $options): MockResponse => $this->respond($method, $url, $options));
    }

    /** Liczba niewykorzystanych interakcji. */
    public function remaining(): int
    {
        return array_sum(array_map(count(...), $this->queues));
    }

    /** @param array<mixed> $options */
    private function respond(string $method, string $url, array $options): MockResponse
    {
        $path = parse_url($url, PHP_URL_PATH);
        $key = $method . ' ' . (is_string($path) ? $path : '/');
        $body = is_string($options['body'] ?? null) ? $options['body'] : '';
        $query = parse_url($url, PHP_URL_QUERY);
        $this->requests[] = ['method' => $method, 'path' => is_string($path) ? $path : '/', 'query' => is_string($query) ? urldecode($query) : '', 'body' => $body];
        if ($method === 'POST' && str_ends_with($key, '/invoices') && $body !== '') {
            $sent = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            if (is_array($sent) && is_string($sent['invoiceHash'] ?? null)) {
                $this->vars['sent_hash'] = $sent['invoiceHash'];
            }
        }
        if ($this->live !== null) {
            return $this->passThrough($this->live, $method, $url, $options, is_string($path) ? $path : '/');
        }
        $next = array_shift($this->queues[$key]) ?? throw new \LogicException('Żądanie spoza nagrania: ' . $key);
        if (is_string($next['error'] ?? null)) {
            return new MockResponse('', ['error' => $next['error']]);
        }
        $headers = [];
        foreach (is_array($next['headers'] ?? null) ? $next['headers'] : [] as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $headers[] = $name . ': ' . $value;
            }
        }
        $content = is_string($next['body_text'] ?? null) ? $next['body_text'] : (array_key_exists('body', $next) ? json_encode($next['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '');
        $content = (string) preg_replace_callback('/\{\{([a-z_]+)\}\}/', fn(array $match): string => $this->vars[$match[1]] ?? throw new \LogicException('Brak zmiennej nagrania ' . $match[1]), $content);
        return new MockResponse($content, ['http_code' => is_int($next['status'] ?? null) ? $next['status'] : 200, 'response_headers' => $headers]);
    }

    /**
     * Prawdziwe żądanie trybu live: te same metoda, adres, nagłówki i treść, bez ponowień; błąd transportu wraca jako
     * błąd odpowiedzi, jak w nagraniu `error`.
     *
     * @param array<mixed> $options
     */
    private function passThrough(HttpClientInterface $live, string $method, string $url, array $options, string $path): MockResponse
    {
        $forward = array_intersect_key($options, array_flip(['headers', 'body', 'timeout', 'max_duration']));
        $headers = [];
        foreach (is_array($options['headers'] ?? null) ? $options['headers'] : [] as $name => $value) {
            if (is_string($value)) {
                $headers[] = is_string($name) ? $name . ': ' . $value : $value;
            }
        }
        $forward['headers'] = $headers;
        try {
            $response = $live->request($method, $url, $forward);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $exception) {
            $this->recorded[] = ['method' => $method, 'path' => $path, 'status' => 0, 'body_text' => '', 'error' => $exception::class];
            return new MockResponse('', ['error' => 'live transport error']);
        }
        $this->recorded[] = ['method' => $method, 'path' => $path, 'status' => $status, 'body_text' => $content];
        return new MockResponse($content, ['http_code' => $status]);
    }
}
