<?php

declare(strict_types=1);

namespace App\Connector\KsefDocs;

use App\Config\ConnectorConfiguration;
use App\Http\ConnectorError;
use App\Http\ConnectorException;
use App\Http\ConnectorHttpClient;
use App\Http\ConnectorRequestContext;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Dokumentacja KSeF API z repozytorium CIRFMF/ksef-docs tylko do odczytu (docs/modules/obserwacja-prawa.md, D-112):
 * changelog API jako lista wersji z datami wdrożeń. Tylko `GET` na adres z `ksef_docs.base_url` (`raw.githubusercontent.com`)
 * przez licznik `ksef_docs.read`; plik publiczny, bez klucza.
 */
final readonly class KsefDocsConnector
{
    public function __construct(private ConnectorHttpClient $http, private ConnectorConfiguration $configuration, private ClockInterface $clock, private string $baseUrl) {}

    /**
     * Wersje z changelogu (`### Wersja …`) z tabelą dat wdrożeń per środowisko, w kolejności z pliku.
     *
     * @return list<array{version:string,dates:string}>
     */
    public function changelog(string $file): array
    {
        if (!self::isFile($file)) {
            throw new \InvalidArgumentException('Niepoprawny plik ksef-docs.');
        }
        return self::parseChangelog($this->get('changelog', $this->fileUrl($file)));
    }

    public static function isFile(string $file): bool
    {
        return preg_match('~^[a-z0-9-]+(/[a-z0-9-]+)*\.md$~D', $file) === 1;
    }

    public function fileUrl(string $file): string
    {
        return $this->baseUrl . '/' . $file;
    }

    /** @return list<array{version:string,dates:string}> */
    public static function parseChangelog(string $body): array
    {
        $versions = [];
        $dates = [];
        foreach (preg_split('/\R/u', $body) ?: [] as $line) {
            if (preg_match('/^###\s+(\S.*?)\s*$/u', $line, $heading) === 1) {
                $versions[] = $heading[1];
                $dates[] = [];
            } elseif ($versions !== [] && preg_match('/^\|\s*\*\*([A-Z]+)\*\*\s*\|\s*([^|]*?)\s*\|/u', $line, $row) === 1) {
                $dates[count($versions) - 1][] = trim($row[1] . ' ' . $row[2]);
            }
        }
        if ($versions === []) {
            throw new ConnectorException(ConnectorError::HttpError);
        }
        return array_map(static fn(string $version, array $rows): array => ['version' => $version, 'dates' => implode(', ', $rows)], $versions, $dates);
    }

    private function get(string $operation, string $url): string
    {
        $correlation = new UuidV7(UuidV7::generate($this->clock->now()));
        $response = $this->http->connectorRequest(new ConnectorRequestContext('ksef_docs', 'default', $operation, $this->configuration->environment(), correlationId: $correlation), 'ksef_docs.read', 'GET', $url, ['headers' => ['Accept' => 'text/plain']]);
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
