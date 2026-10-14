<?php

declare(strict_types=1);

namespace App\Connector\Latarnia;

use App\Config\ConnectorConfiguration;
use App\Http\ConnectorHttpClient;
use App\Http\ConnectorRequestContext;
use Symfony\Component\Clock\ClockInterface;

/**
 * Konektor `latarnia`: publiczne API bez uwierzytelnienia, `/status` i `/messages` (docs/modules/ksef.md, „Awarie KSeF
 * i Latarnia”). Cel idzie za `KSEF_TARGET`; przy `demo` Latarni nie ma, więc `enabled()` zwraca `false`. Każde
 * wywołanie liczy grupa `latarnia.status` i zostawia wiersz `connector_call`.
 */
final readonly class LatarniaConnector
{
    public const BUCKET = 'latarnia.status';

    /** @param array<string,string> $endpoints adres bazowy per cel (`latarnia.endpoints`) */
    public function __construct(private ConnectorHttpClient $http, private ConnectorConfiguration $configuration, private ClockInterface $clock, private array $endpoints) {}

    public function enabled(): bool
    {
        return isset($this->endpoints[$this->configuration->target('latarnia')]);
    }

    public function status(): LatarniaStatus
    {
        $data = $this->get('status', '/status');
        $status = $data['status'] ?? null;
        $title = null;
        foreach (is_array($data['messages'] ?? null) ? $data['messages'] : [] as $message) {
            if (is_array($message) && is_string($message['title'] ?? null)) {
                $title = $message['title'];
                break;
            }
        }
        return new LatarniaStatus(is_string($status) ? $status : throw new \UnexpectedValueException('Odpowiedź Latarni bez statusu.'), $title, \DateTimeImmutable::createFromInterface($this->clock->now()));
    }

    /** @return list<array<string,mixed>> komunikaty z ostatnich 30 dni */
    public function messages(): array
    {
        $data = $this->get('messages', '/messages');
        $list = array_is_list($data) ? $data : (is_array($data['messages'] ?? null) ? $data['messages'] : []);
        $messages = [];
        foreach ($list as $message) {
            if (is_array($message)) {
                $messages[] = array_filter($message, static fn(mixed $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
            }
        }
        return $messages;
    }

    /** @return array<mixed> */
    private function get(string $operation, string $path): array
    {
        $target = $this->configuration->target('latarnia');
        $base = $this->endpoints[$target] ?? throw new \LogicException('Latarnia nie ma środowiska dla celu ' . $target . '.');
        $response = $this->http->connectorRequest(new ConnectorRequestContext('latarnia', $target, $operation, $this->configuration->environment()), self::BUCKET, 'GET', rtrim($base, '/') . $path);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf('Latarnia zwróciła HTTP %d.', $response->getStatusCode()));
        }
        return $response->toArray(false);
    }
}
