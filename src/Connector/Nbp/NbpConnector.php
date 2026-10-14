<?php

declare(strict_types=1);

namespace App\Connector\Nbp;

use App\Config\ConnectorConfiguration;
use App\Fx\FxRateUnavailable;
use App\Fx\NbpRateSource;
use App\Http\ConnectorError;
use App\Http\ConnectorException;
use App\Http\ConnectorHttpClient;
use App\Http\ConnectorRequestContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Tabela A NBP dla zakresu dat (docs/modules/rejestry-kursy.md, „Kursy NBP: użycie API”). Tylko `GET` na `api.nbp.pl`
 * przez licznik `nbp.rates`; 404 to pusty zakres. Kurs `mid` czyta się z surowego JSON jako tekst, bez `float`.
 */
final readonly class NbpConnector implements NbpRateSource
{
    public const BASE_URL = 'https://api.nbp.pl/api/exchangerates/rates/a';
    public const MAX_DAYS = 93;

    public function __construct(private ConnectorHttpClient $http, private ConnectorConfiguration $configuration, private Connection $db, private ClockInterface $clock) {}

    public function range(string $currency, string $from, string $to): array
    {
        $raw = $this->fetch($currency, $from, $to);
        if ($raw['status'] === 404) {
            return [];
        }
        if ($raw['status'] === 429) {
            throw new ConnectorException(ConnectorError::RateLimited);
        }
        if ($raw['status'] !== 200) {
            throw new ConnectorException(ConnectorError::HttpError);
        }
        return self::parse($raw['body'], $currency, $raw['connectorCallId']);
    }

    /**
     * Surowa odpowiedź dla zakresu; czyta ją też `app:fixtures:record nbp <waluta>-<od>-<do>`.
     *
     * @return array{url:string,status:int,body:string,connectorCallId:?string}
     */
    public function fetch(string $currency, string $from, string $to): array
    {
        if (!preg_match('/^[A-Z]{3}$/D', $currency) || !self::date($from) || !self::date($to) || $from > $to) {
            throw new \InvalidArgumentException('Niepoprawne zapytanie o kurs NBP.');
        }
        $days = (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days;
        if ($days + 1 > self::MAX_DAYS) {
            throw new \InvalidArgumentException(sprintf('Zakres NBP ma najwyżej %d dni.', self::MAX_DAYS));
        }
        $correlation = new UuidV7(UuidV7::generate($this->clock->now()));
        $url = self::url($currency, $from, $to);
        $response = $this->http->connectorRequest(new ConnectorRequestContext('nbp', 'default', 'rates', $this->configuration->environment(), correlationId: $correlation), 'nbp.rates', 'GET', $url, ['headers' => ['Accept' => 'application/json']]);
        $status = $response->getStatusCode();
        $body = $response->getContent(false);
        $callId = $this->db->fetchOne('SELECT id FROM connector_call WHERE correlation_id=? ORDER BY started_at DESC LIMIT 1', [$correlation->toRfc4122()]);
        return ['url' => $url,'status' => $status,'body' => $body,'connectorCallId' => is_string($callId) ? $callId : null];
    }

    public static function url(string $currency, string $from, string $to): string
    {
        return sprintf('%s/%s/%s/%s/?format=json', self::BASE_URL, strtolower($currency), $from, $to);
    }

    /**
     * @return list<array{no:string,effectiveDate:string,mid:string,connectorCallId:?string}>
     */
    public static function parse(string $body, string $currency, ?string $callId): array
    {
        // `mid` jako liczba JSON dałby `float`; cytujemy ją przed dekodowaniem.
        $quoted = preg_replace('/("mid"\s*:\s*)(-?[0-9]+(?:\.[0-9]+)?(?:[eE][-+]?[0-9]+)?)/', '$1"$2"', $body);
        $data = json_decode((string) $quoted, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['table'] ?? null) !== 'A' || !is_string($data['code'] ?? null) || strtoupper($data['code']) !== $currency || !is_array($data['rates'] ?? null)) {
            throw new FxRateUnavailable('Odpowiedź NBP bez tabeli A dla tej waluty.');
        }
        $rows = [];
        foreach ($data['rates'] as $rate) {
            if (!is_array($rate) || !is_string($rate['no'] ?? null) || !is_string($rate['effectiveDate'] ?? null) || !is_string($rate['mid'] ?? null)
                || !preg_match('~^\d{3}/A/NBP/\d{4}$~D', $rate['no']) || !self::date($rate['effectiveDate']) || !preg_match('/^[0-9]+\.[0-9]{1,6}$/D', $rate['mid'])) {
                throw new FxRateUnavailable('Niepoprawny wiersz tabeli A NBP.');
            }
            $rows[] = ['no' => $rate['no'],'effectiveDate' => $rate['effectiveDate'],'mid' => $rate['mid'],'connectorCallId' => $callId];
        }
        return $rows;
    }

    private static function date(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
