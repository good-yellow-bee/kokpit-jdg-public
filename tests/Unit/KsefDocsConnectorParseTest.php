<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Connector\KsefDocs\KsefDocsConnector;
use App\Http\ConnectorError;
use App\Http\ConnectorException;
use PHPUnit\Framework\TestCase;

/** Odczyt changelogu ksef-docs (D-112): wersje z datami wdrożeń, ścieżki plików i odrzucenie pliku bez wersji. */
final class KsefDocsConnectorParseTest extends TestCase
{
    public function testKsefDocsChangelogVersionsWithDeploymentDates(): void
    {
        $body = "## Zmiany\n\n### Wersja 2.1.0\n| Środowisko | Data |\n| --- | ---: |\n| **TEST** |  01.01.2099 |\n| **PROD**   | |\n\n- **Zmiana**  \n| **DEMO** | 02.01.2099 |\n### Wersja 2.0.0 RC1  \r\n- opis\n";
        self::assertSame([['version' => 'Wersja 2.1.0', 'dates' => 'TEST 01.01.2099, PROD, DEMO 02.01.2099'], ['version' => 'Wersja 2.0.0 RC1', 'dates' => '']], KsefDocsConnector::parseChangelog($body));
        $this->expectException(ConnectorException::class);
        KsefDocsConnector::parseChangelog("## Zmiany\n| **TEST** | 01.01.2099 |\n");
    }

    public function testKsefDocsFilesAreValidatedBeforeAnyRequest(): void
    {
        self::assertTrue(KsefDocsConnector::isFile('api-changelog.md'));
        self::assertTrue(KsefDocsConnector::isFile('limity/limity-api.md'));
        foreach (['../x.md', 'api-changelog.json', 'a//b.md', '/api-changelog.md', 'API.md', "api-changelog.md\n"] as $file) {
            self::assertFalse(KsefDocsConnector::isFile($file), $file);
        }
        try {
            KsefDocsConnector::parseChangelog('');
            self::fail('Przyjęto pusty plik.');
        } catch (ConnectorException $exception) {
            self::assertSame(ConnectorError::HttpError, $exception->error);
        }
    }
}
