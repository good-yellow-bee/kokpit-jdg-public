<?php

declare(strict_types=1);

namespace App\Connector\WhiteList;

use App\Config\ConnectorConfiguration;
use App\Connector\RegistryResult;
use App\Http\ConnectorError;
use App\Http\ConnectorException;
use App\Http\ConnectorHttpClient;
use App\Http\ConnectorRequestContext;
use App\Rate\RateLimitExceededException;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Wykaz podatników VAT, `search` i `check` (docs/modules/rejestry-kursy.md, „Biała lista”). Dwa liczniki,
 * `white_list.search` i `white_list.check`, z progami z konfiguracji. Między 22:00 a 0:00 (Europe/Warsaw) konektor
 * nie pyta wykazu: zadanie czeka do północy jak przy progu licznika.
 */
final readonly class WhiteListConnector
{
    public const QUIET_FROM_HOUR = 22;

    /** @param array<string,string> $endpoints adres bazowy API per środowisko (`test`, `prod`), bez końcowego `/` */
    public function __construct(private ConnectorHttpClient $http, private ConnectorConfiguration $configuration, private Connection $db, private ClockInterface $clock, private BusinessDate $dates, private array $endpoints) {}

    /** Status VAT, nazwa i rachunki podmiotu na dzień `date`. */
    public function search(string $nip, string $date): RegistryResult
    {
        $raw = $this->fetch('search', $nip, null, $date);
        return self::parseSearch($this->body($raw), $raw['connectorCallId']);
    }

    /** `TAK` albo `NIE`: czy rachunek należy do czynnego podatnika VAT na dzień `date`. */
    public function check(string $nip, string $account, string $date): RegistryResult
    {
        $raw = $this->fetch('check', $nip, $account, $date);
        return self::parseCheck($this->body($raw), $raw['connectorCallId']);
    }

    /**
     * Surowa odpowiedź; czyta ją też `app:fixtures:record white_list <scenariusz>`.
     *
     * @return array{url:string,status:int,body:string,connectorCallId:?string}
     */
    public function fetch(string $method, string $nip, ?string $account, string $date): array
    {
        $account = $account === null ? null : str_replace(' ', '', $account);
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!in_array($method, ['search','check'], true) || !preg_match('/^[0-9]{10}$/D', $nip) || $day === false || $day->format('Y-m-d') !== $date
            || ($method === 'check') !== ($account !== null) || ($account !== null && !preg_match('/^[0-9]{26}$/D', $account))) {
            throw new \InvalidArgumentException('Niepoprawne zapytanie do białej listy.');
        }
        $now = $this->clock->now();
        if ((int) $now->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('G') >= self::QUIET_FROM_HOUR) {
            throw new RateLimitExceededException($this->dates->nextMidnight());
        }
        $environment = $this->configuration->environment();
        $base = $this->endpoints[$environment] ?? throw new \LogicException('Brak adresu białej listy dla środowiska.');
        $url = $method === 'search'
            ? sprintf('%s/search/nip/%s?date=%s', $base, $nip, $date)
            : sprintf('%s/check/nip/%s/bank-account/%s?date=%s', $base, $nip, $account, $date);
        $correlation = new UuidV7(UuidV7::generate($now));
        $response = $this->http->connectorRequest(new ConnectorRequestContext('white_list', $environment, $method, $environment, correlationId: $correlation), 'white_list.' . $method, 'GET', $url, ['headers' => ['Accept' => 'application/json']]);
        $status = $response->getStatusCode();
        $body = $response->getContent(false);
        $callId = $this->db->fetchOne('SELECT id FROM connector_call WHERE correlation_id=? ORDER BY started_at DESC LIMIT 1', [$correlation->toRfc4122()]);
        return ['url' => $url,'status' => $status,'body' => $body,'connectorCallId' => is_string($callId) ? $callId : null];
    }

    public static function parseSearch(string $body, ?string $callId): RegistryResult
    {
        $result = self::result($body);
        $subject = $result['subject'] ?? null;
        if ($subject !== null && !is_array($subject)) {
            throw new ConnectorException(ConnectorError::HttpError);
        }
        $status = is_array($subject) && is_string($subject['statusVat'] ?? null) ? $subject['statusVat'] : null;
        $accounts = [];
        foreach (is_array($subject) && is_array($subject['accountNumbers'] ?? null) ? $subject['accountNumbers'] : [] as $account) {
            if (is_string($account) && preg_match('/^[0-9]{26}$/D', $account)) {
                $accounts[] = $account;
            }
        }
        // „Czynny” i „Zwolniony” potwierdzają NIP; „Niezarejestrowany” albo brak podmiotu to `invalid`.
        $verdict = in_array($status, ['Czynny','Zwolniony'], true) ? 'valid' : 'invalid';
        $name = is_array($subject) && is_string($subject['name'] ?? null) ? $subject['name'] : null;
        return new RegistryResult('white_list', $verdict, $body, self::requestId($result), self::requestDateTime($result), $callId, details: ['name' => $name,'status_vat' => $status,'account_numbers' => $accounts]);
    }

    public static function parseCheck(string $body, ?string $callId): RegistryResult
    {
        $result = self::result($body);
        $assigned = $result['accountAssigned'] ?? null;
        if (!in_array($assigned, ['TAK','NIE'], true)) {
            throw new ConnectorException(ConnectorError::HttpError);
        }
        return new RegistryResult('white_list', $assigned === 'TAK' ? 'valid' : 'invalid', $body, self::requestId($result), self::requestDateTime($result), $callId);
    }

    /** @param array{url:string,status:int,body:string,connectorCallId:?string} $raw */
    private function body(array $raw): string
    {
        if ($raw['status'] === 429) {
            throw new ConnectorException(ConnectorError::RateLimited);
        }
        if ($raw['status'] !== 200) {
            // 400 z kodem `WL-…` to błąd zapytania, nie wynik; wiersz `verification` nie powstaje.
            throw new ConnectorException(ConnectorError::HttpError);
        }
        return $raw['body'];
    }

    /** @return array<mixed> */
    private static function result(string $body): array
    {
        try {
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ConnectorException(ConnectorError::HttpError);
        }
        if (!is_array($data) || !is_array($data['result'] ?? null)) {
            throw new ConnectorException(ConnectorError::HttpError);
        }
        return $data['result'];
    }

    /** @param array<mixed> $result */
    private static function requestId(array $result): ?string
    {
        return is_string($result['requestId'] ?? null) && $result['requestId'] !== '' ? $result['requestId'] : null;
    }

    /**
     * `requestDateTime` ma format `dd-MM-yyyy HH:mm:ss` w czasie polskim; zapis w UTC.
     *
     * @param array<mixed> $result
     */
    private static function requestDateTime(array $result): ?string
    {
        $value = $result['requestDateTime'] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $instant = \DateTimeImmutable::createFromFormat('!d-m-Y H:i:s', $value, new \DateTimeZone('Europe/Warsaw'));
        return $instant === false ? null : $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
