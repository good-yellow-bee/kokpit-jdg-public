<?php

declare(strict_types=1);

namespace App\Connector\Ksef;

use App\Config\ConnectorConfiguration;
use App\Http\ConnectorHttpClient;
use App\Http\ConnectorRequestContext;
use App\Rate\RateLimitExceededException;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Wywołanie KSeF API 2.0 przez `ConnectorHttpClient` (D-70): adres bazowy z `ksef.api_base` dla celu z `KSEF_TARGET`,
 * więc działają `HostAllowlist`, `rate_counter` i `connector_call`. 429 i blokada licznika dają `RateLimited`,
 * inna odmowa `RequestRefused` z `exceptionCode` (21184 to `SessionUnavailable`). Klucze publiczne MF trzyma
 * w pamięci procesu na cel (docs/modules/ksef.md, „Przechowywanie”).
 */
final class KsefApi
{
    /** @var array<string,array<string,array{id:string,pem:string}>> cel → użycie → certyfikat */
    private array $keys = [];

    /** @param array<string,string> $apiBase cel → adres bazowy API, np. `test` → `https://api-test.ksef.mf.gov.pl/v2` */
    public function __construct(
        private readonly ConnectorHttpClient $http,
        private readonly ConnectorConfiguration $configuration,
        private readonly array $apiBase,
    ) {}

    public function target(): string
    {
        return $this->configuration->target('ksef');
    }

    /**
     * @param array<string,mixed>|string|null $body tablica jako JSON, tekst jako XML
     * @param array<string,scalar> $query
     */
    public function request(?KsefCall $call, string $operation, string $bucket, string $method, string $path, array|string|null $body = null, ?string $bearer = null, array $query = [], string $accept = 'application/json'): ResponseInterface
    {
        $target = $this->target();
        $base = $this->apiBase[$target] ?? throw new KsefException(KsefError::GatewayUnavailable, 'target_without_api_base');
        $context = new ConnectorRequestContext('ksef', $target, $operation, $this->configuration->environment(), $call === null ? null : Uuid::fromString($call->approvalId), $call === null ? null : Uuid::fromString($call->correlationId));
        $options = ['headers' => ['Accept' => $accept]];
        if (is_array($body)) {
            $options['body'] = json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $options['headers']['Content-Type'] = 'application/json';
        } elseif (is_string($body)) {
            $options['body'] = $body;
            $options['headers']['Content-Type'] = 'application/xml';
        }
        if ($bearer !== null) {
            $options['headers']['Authorization'] = 'Bearer ' . $bearer;
        }
        if ($query !== []) {
            $options['query'] = $query;
        }
        try {
            $response = $this->http->connectorRequest($context, $bucket, $method, rtrim($base, '/') . $path, $options);
        } catch (RateLimitExceededException) {
            throw new KsefException(KsefError::RateLimited);
        }
        $status = $response->getStatusCode();
        if ($status === 429) {
            throw new KsefException(KsefError::RateLimited);
        }
        if ($status >= 400) {
            $code = self::exceptionCode($response->getContent(false)) ?? (string) $status;
            throw new KsefException($code === KsefError::SessionUnavailable->value ? KsefError::SessionUnavailable : KsefError::RequestRefused, $code);
        }
        return $response;
    }

    /**
     * @param array<string,mixed>|null $body
     * @param array<string,scalar> $query
     * @return array<mixed>
     */
    public function json(?KsefCall $call, string $operation, string $bucket, string $method, string $path, ?array $body = null, ?string $bearer = null, array $query = []): array
    {
        $data = json_decode($this->request($call, $operation, $bucket, $method, $path, $body, $bearer, $query)->getContent(false), true, 32, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : throw new KsefException(KsefError::RequestRefused, 'response_not_object');
    }

    /**
     * Certyfikat MF do szyfrowania: `KsefTokenEncryption` albo `SymmetricKeyEncryption` (`GET /security/public-key-certificates`).
     *
     * @return array{id:string,pem:string}
     */
    public function publicKey(string $usage): array
    {
        $target = $this->target();
        if (!isset($this->keys[$target])) {
            $this->keys[$target] = [];
            foreach ($this->json(null, 'public_keys', 'ksef.default', 'GET', '/security/public-key-certificates') as $certificate) {
                if (!is_array($certificate) || !is_string($certificate['certificate'] ?? null) || !is_array($certificate['usage'] ?? null)) {
                    continue;
                }
                $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split($certificate['certificate'], 64, "\n") . "-----END CERTIFICATE-----\n";
                foreach ($certificate['usage'] as $use) {
                    if (is_string($use)) {
                        $this->keys[$target][$use] = ['id' => is_string($certificate['publicKeyId'] ?? null) ? $certificate['publicKeyId'] : '', 'pem' => $pem];
                    }
                }
            }
        }
        return $this->keys[$target][$usage] ?? throw new KsefException(KsefError::RequestRefused, 'mf_key_missing');
    }

    /** `exceptionCode` z `exception.exceptionDetailList[0]` odpowiedzi błędu; bez opisu, bo opis może mieć dane. */
    public static function exceptionCode(string $content): ?string
    {
        $data = json_decode($content, true, 16);
        $exception = is_array($data) && is_array($data['exception'] ?? null) ? $data['exception'] : [];
        $details = is_array($exception['exceptionDetailList'] ?? null) ? $exception['exceptionDetailList'] : [];
        $first = is_array($details[0] ?? null) ? $details[0] : [];
        return is_int($first['exceptionCode'] ?? null) ? (string) $first['exceptionCode'] : null;
    }
}
