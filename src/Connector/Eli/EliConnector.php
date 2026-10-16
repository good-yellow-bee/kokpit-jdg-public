<?php

declare(strict_types=1);

namespace App\Connector\Eli;

use App\Config\ConnectorConfiguration;
use App\Http\ConnectorError;
use App\Http\ConnectorException;
use App\Http\ConnectorHttpClient;
use App\Http\ConnectorRequestContext;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * API Sejmu tylko do odczytu (docs/modules/obserwacja-prawa.md, D-75): powiązania aktu w ELI i przebieg procesu
 * legislacyjnego. Tylko `GET` na adres z `eli.base_url` (`api.sejm.gov.pl`) przez licznik `eli.read`; API publiczne, bez klucza.
 */
final readonly class EliConnector
{
    public function __construct(private ConnectorHttpClient $http, private ConnectorConfiguration $configuration, private ClockInterface $clock, private string $baseUrl) {}

    /**
     * Powiązania aktu (`/eli/acts/{ELI}/references`) jako lista `kategoria → akty`.
     *
     * @return array<string, list<array{eli:string,title:string,displayAddress:string,date:?string}>>
     */
    public function references(string $eli): array
    {
        if (!self::isEli($eli)) {
            throw new \InvalidArgumentException('Niepoprawny identyfikator ELI.');
        }
        return self::parseReferences($this->get('references', $this->referencesUrl($eli)));
    }

    /**
     * Przebieg procesu legislacyjnego (`/sejm/term{N}/processes/{numer}`): etapy spłaszczone w kolejności z API.
     *
     * @return array{title:string,passed:bool,stages:list<string>}
     */
    public function process(string $process): array
    {
        if (!self::isProcess($process)) {
            throw new \InvalidArgumentException('Niepoprawny proces legislacyjny.');
        }
        return self::parseProcess($this->get('process', $this->processUrl($process)));
    }

    /** Surowa odpowiedź; czyta ją też `app:fixtures:record eli <scenariusz>`. */
    public function fetch(string $operation, string $url): string
    {
        return $this->get($operation, $url);
    }

    public static function isEli(string $eli): bool
    {
        return preg_match('~^(DU|MP)/[0-9]{4}/[0-9]{1,5}$~D', $eli) === 1;
    }

    public static function isProcess(string $process): bool
    {
        return preg_match('~^term[0-9]{1,2}/[0-9]{1,6}(-[A-Z0-9]{1,4})?$~D', $process) === 1;
    }

    public function referencesUrl(string $eli): string
    {
        return $this->baseUrl . '/eli/acts/' . $eli . '/references';
    }

    public function processUrl(string $process): string
    {
        [$term, $number] = explode('/', $process, 2);
        return $this->baseUrl . '/sejm/' . $term . '/processes/' . $number;
    }

    /** @return array<string, list<array{eli:string,title:string,displayAddress:string,date:?string}>> */
    public static function parseReferences(string $body): array
    {
        $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new ConnectorException(ConnectorError::HttpError);
        }
        $result = [];
        foreach ($data as $category => $items) {
            if (!is_string($category) || !is_array($items)) {
                throw new ConnectorException(ConnectorError::HttpError);
            }
            foreach ($items as $item) {
                $act = is_array($item) ? ($item['act'] ?? null) : null;
                if (!is_array($act) || !is_string($act['ELI'] ?? null) || !self::isEli($act['ELI'])) {
                    throw new ConnectorException(ConnectorError::HttpError);
                }
                $date = $item['date'] ?? $act['promulgation'] ?? null;
                $result[$category][] = [
                    'eli' => $act['ELI'],
                    'title' => is_string($act['title'] ?? null) ? $act['title'] : '',
                    'displayAddress' => is_string($act['displayAddress'] ?? null) ? $act['displayAddress'] : $act['ELI'],
                    'date' => is_string($date) ? $date : null,
                ];
            }
        }
        return $result;
    }

    /** @return array{title:string,passed:bool,stages:list<string>} */
    public static function parseProcess(string $body): array
    {
        $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_string($data['title'] ?? null) || !is_array($data['stages'] ?? null)) {
            throw new ConnectorException(ConnectorError::HttpError);
        }
        return ['title' => $data['title'], 'passed' => ($data['passed'] ?? false) === true, 'stages' => self::stages($data['stages'])];
    }

    /**
     * @param array<mixed> $stages
     * @return list<string>
     */
    private static function stages(array $stages): array
    {
        $flat = [];
        foreach ($stages as $stage) {
            if (!is_array($stage) || !is_string($stage['stageName'] ?? null)) {
                throw new ConnectorException(ConnectorError::HttpError);
            }
            $flat[] = trim((is_string($stage['date'] ?? null) ? $stage['date'] . ' ' : '') . $stage['stageName'] . (is_string($stage['decision'] ?? null) && $stage['decision'] !== '' ? ': ' . $stage['decision'] : ''));
            if (is_array($stage['children'] ?? null)) {
                array_push($flat, ...self::stages($stage['children']));
            }
        }
        return $flat;
    }

    private function get(string $operation, string $url): string
    {
        $correlation = new UuidV7(UuidV7::generate($this->clock->now()));
        $response = $this->http->connectorRequest(new ConnectorRequestContext('eli', 'default', $operation, $this->configuration->environment(), correlationId: $correlation), 'eli.read', 'GET', $url, ['headers' => ['Accept' => 'application/json']]);
        $status = $response->getStatusCode();
        if ($status === 429) {
            throw new ConnectorException(ConnectorError::RateLimited);
        }
        if ($status !== 200) {
            throw new ConnectorException(ConnectorError::HttpError);
        }
        return $response->getContent(false);
    }
}
