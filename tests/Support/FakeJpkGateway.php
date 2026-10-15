<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Config\ConnectorHosts;
use App\Connector\Jpk\JpkGateway;
use App\Http\ConnectorCallRecorder;
use App\Http\ConnectorHttpClient;
use App\Http\HostAllowlist;
use App\Rate\RateCounter;
use App\Rate\RateLimitAlerts;
use App\Security\Redactor;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * `FakeJpkGateway` F5.1 (docs/phases/f5-jpk.md): prawdziwy `JpkGateway` na `ConnectorHttpClient` z `HostAllowlist`,
 * `rate_counter` i `connector_call`, a pod spodem odtwarzanie ręcznie pisanych, fikcyjnych nagrań
 * `tests/Fixtures/Jpk/api-*.json` (`KsefRecordings`: kolejka per metoda i ścieżka). Żądanie spoza nagrania kończy test
 * błędem, więc nic nie wychodzi do sieci; bajty Put Blob zostają w `requests` do odszyfrowania w teście.
 */
final class FakeJpkGateway
{
    public const ENDPOINTS = ['test' => 'https://test-e-dokumenty.mf.gov.pl/api/Storage/'];
    public const CIPHERS = 'ECDHE-RSA-AES256-GCM-SHA384:ECDHE-RSA-AES128-GCM-SHA256';

    public readonly KsefRecordings $recordings;
    public readonly ConnectorHttpClient $http;
    public readonly RateCounter $rates;
    public readonly JpkGateway $gateway;

    /**
     * @param array<string,int> $limits limity dobowe `connector.daily_limits`
     * @param ?HttpClientInterface $live prawdziwy klient trybu live (`@group live`, F5.3); bez niego tylko nagrania
     */
    public function __construct(Connection $db, MockClock $clock, Redactor $redactor, array $limits = ['jpk.init_upload' => 10], ?HttpClientInterface $live = null)
    {
        $this->recordings = new KsefRecordings($live);
        $configuration = ConnectorTestTools::configuration(limits: $limits);
        $dates = new BusinessDate($clock);
        $allowlist = new HostAllowlist(ConnectorHosts::HOSTS, $configuration, $this->recordings->client());
        $this->rates = new RateCounter($db, $clock, $dates, $limits);
        $this->http = new ConnectorHttpClient($allowlist, $allowlist, new ConnectorCallRecorder($db, $clock), $this->rates, $clock, true, $dates, $redactor, new RateLimitAlerts($db, $clock));
        $this->gateway = new JpkGateway($this->http, $allowlist, $configuration, self::ENDPOINTS, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT, self::CIPHERS);
    }

    /** Nagrania `tests/Fixtures/Jpk/<nazwa>.json`, np. `api-init-upload`, `api-status-200`. */
    public function load(string ...$names): void
    {
        foreach ($names as $name) {
            $this->recordings->load('Jpk/' . $name . '.json');
        }
    }

    /** @return list<string> bajty wysłane przez Put Blob, w kolejności */
    public function puts(): array
    {
        return array_values(array_map(static fn(array $request): string => $request['body'], array_filter($this->recordings->requests, static fn(array $request): bool => $request['method'] === 'PUT')));
    }

    /** Liczba żądań do ścieżki kończącej się podanym fragmentem, np. `InitUploadSigned`. */
    public function requests(string $pathSuffix): int
    {
        return count(array_filter($this->recordings->requests, static fn(array $request): bool => str_ends_with($request['path'], $pathSuffix)));
    }

    /** Metadane `InitUpload` z ostatniego `InitUploadSigned`. */
    public function metadata(): string
    {
        foreach (array_reverse($this->recordings->requests) as $request) {
            if (str_ends_with($request['path'], '/InitUploadSigned')) {
                return $request['body'];
            }
        }
        throw new \LogicException('Brak InitUploadSigned.');
    }
}
