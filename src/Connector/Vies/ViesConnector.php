<?php

declare(strict_types=1);

namespace App\Connector\Vies;

use App\Config\ConnectorConfiguration;
use App\Connector\RegistryResult;
use App\Http\ConnectorError;
use App\Http\ConnectorException;
use App\Http\ConnectorHttpClient;
use App\Http\ConnectorRequestContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * VIES REST `check-vat-number` (docs/modules/rejestry-kursy.md, „VIES”). Adres pochodzi z konfiguracji wdrożenia:
 * poza PROD tylko `check-vat-test-service`. Żądanie zawsze ma numer pytającego, więc VIES zwraca `requestIdentifier`.
 * Błąd przychodzi też jako HTTP 200 z `actionSucceed` = false, więc treść sprawdzamy przy każdym statusie.
 */
final readonly class ViesConnector
{
    /** @param array<string,string> $endpoints adres per środowisko (`test`, `prod`) */
    public function __construct(private ConnectorHttpClient $http, private ConnectorConfiguration $configuration, private Connection $db, private ClockInterface $clock, private array $endpoints) {}

    public function check(string $countryCode, string $vatNumber, string $requesterNip): RegistryResult
    {
        $raw = $this->fetch($countryCode, $vatNumber, $requesterNip);
        if ($raw['status'] === 429) {
            throw new ConnectorException(ConnectorError::RateLimited);
        }
        return self::parse($raw['body'], $raw['status'], $raw['connectorCallId']);
    }

    /**
     * Surowa odpowiedź; czyta ją też `app:fixtures:record vies <scenariusz>`.
     *
     * @return array{url:string,status:int,body:string,connectorCallId:?string}
     */
    public function fetch(string $countryCode, string $vatNumber, string $requesterNip): array
    {
        $vatNumber = strtoupper(str_replace([' ', '-', '.'], '', $vatNumber));
        if (!preg_match('/^[A-Z]{2}$/D', $countryCode) || !preg_match('/^[0-9A-Z+*]{1,20}$/D', $vatNumber) || !preg_match('/^[0-9]{10}$/D', $requesterNip)) {
            throw new \InvalidArgumentException('Niepoprawne zapytanie VIES.');
        }
        $environment = $this->configuration->environment();
        $url = $this->endpoints[$environment] ?? throw new \LogicException('Brak adresu VIES dla środowiska.');
        $correlation = new UuidV7(UuidV7::generate($this->clock->now()));
        $body = json_encode(['countryCode' => $countryCode,'vatNumber' => $vatNumber,'requesterMemberStateCode' => 'PL','requesterNumber' => $requesterNip], JSON_THROW_ON_ERROR);
        $response = $this->http->connectorRequest(new ConnectorRequestContext('vies', $environment, 'check_vat', $environment, correlationId: $correlation), 'vies.check', 'POST', $url, ['headers' => ['Accept' => 'application/json','Content-Type' => 'application/json'],'body' => $body]);
        $status = $response->getStatusCode();
        $content = $response->getContent(false);
        $callId = $this->db->fetchOne('SELECT id FROM connector_call WHERE correlation_id=? ORDER BY started_at DESC LIMIT 1', [$correlation->toRfc4122()]);
        return ['url' => $url,'status' => $status,'body' => $content,'connectorCallId' => is_string($callId) ? $callId : null];
    }

    public static function parse(string $body, int $status, ?string $callId): RegistryResult
    {
        try {
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }
        if (!is_array($data)) {
            // Odpowiedź bez JSON (np. 503 z bramy) traktujemy jak niedostępność usługi.
            return new RegistryResult('vies', 'unverified_retry', $body, null, null, $callId, ViesError::ServiceUnavailable->value, $status >= 500);
        }
        $errors = $data['errorWrappers'] ?? null;
        if (($data['actionSucceed'] ?? null) === false || is_array($errors)) {
            $first = is_array($errors) && is_array($errors[0] ?? null) ? $errors[0] : [];
            $error = ViesError::fromCode(is_string($first['error'] ?? null) ? $first['error'] : '');
            return new RegistryResult('vies', $error->result(), $body, null, null, $callId, $error === ViesError::Other && is_string($first['error'] ?? null) ? $first['error'] : $error->value, $error->retryable());
        }
        if ($status !== 200 || !is_bool($data['valid'] ?? null)) {
            return new RegistryResult('vies', 'unverified_retry', $body, null, null, $callId, ViesError::Other->value, false);
        }
        $requestDate = is_string($data['requestDate'] ?? null) ? self::instant($data['requestDate']) : null;
        $identifier = is_string($data['requestIdentifier'] ?? null) && $data['requestIdentifier'] !== '' ? $data['requestIdentifier'] : null;
        $text = static fn(string $key): ?string => is_string($data[$key] ?? null) && !in_array(trim($data[$key]), ['', '---'], true) ? trim($data[$key]) : null;
        return new RegistryResult('vies', $data['valid'] ? 'valid' : 'invalid', $body, $identifier, $requestDate, $callId, details: ['name' => $text('name'),'address' => $text('address')]);
    }

    private static function instant(string $value): ?string
    {
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        } catch (\Exception) {
            return null;
        }
    }
}
