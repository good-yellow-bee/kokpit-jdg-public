<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Connector\Dummy\DummyConnector;
use App\Security\SecretStore;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ConnectorTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DummyConnectorContractTest extends DatabaseTestCase
{
    public function testRecordingMatchesMethodAndPath(): void
    {
        $record = json_decode(file_get_contents(dirname(__DIR__) . '/Fixtures/Dummy/smoke.json') ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($record);
        self::assertTrue($record['fictional']);
        $mock = new MockHttpClient(static function (string $method, string $url) use ($record): MockResponse {
            self::assertSame($record['method'], $method);
            self::assertSame($record['path'], parse_url($url, PHP_URL_PATH));
            return new MockResponse(json_encode($record['response'], JSON_THROW_ON_ERROR));
        });
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        $dummy = new DummyConnector(ConnectorTestTools::http($this->connection, new MockClock('2026-10-09T10:00:00Z'), $mock), $secrets, ConnectorTestTools::configuration(), 'https://dummy.invalid/call');
        self::assertSame(['status' => 'ok','fictional' => true], $dummy->call());
        self::assertSame(1, $mock->getRequestsCount());
    }
}
