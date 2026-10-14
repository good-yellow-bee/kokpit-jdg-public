<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Http\ConnectorException;
use App\Http\ConnectorRequestContext;
use App\Tests\Support\ConnectorTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ConnectorCallTest extends DatabaseTestCase
{
    public function testHashSizeAndSanitizedPathWithoutContent(): void
    {
        $http = ConnectorTestTools::http($this->connection, new MockClock('2026-10-09T10:00:00Z'), new MockHttpClient(new MockResponse('fictional response')));
        $http->connectorRequest(new ConnectorRequestContext('dummy', 'default', 'call', 'test'), 'dummy.call', 'POST', 'https://dummy.invalid/nip/1111111111?token=fictional', ['body' => 'fictional body']);
        $row = $this->connection->fetchAssociative("SELECT * FROM connector_call WHERE connector='dummy' ORDER BY sequence DESC LIMIT 1");
        self::assertIsArray($row);
        self::assertSame(hash('sha256', 'fictional body'), $row['request_sha256']);
        self::assertSame(14, $row['request_bytes']);
        self::assertSame(hash('sha256', 'fictional response'), $row['response_sha256']);
        self::assertSame('/nip/:id', $row['path']);
        self::assertArrayNotHasKey('request_body', $row);
    }
    public function testRejectedRequestIsLoggedOnceBeforeAnyNetwork(): void
    {
        $mock = new MockHttpClient(static function (): never {
            throw new \LogicException('Sieć jest zabroniona.');
        });
        $http = ConnectorTestTools::http($this->connection, new MockClock('2026-10-09T10:00:00Z'), $mock);
        try {
            $http->connectorRequest(new ConnectorRequestContext('ksef', 'test', 'call', 'test'), 'dummy.call', 'GET', 'https://api.ksef.mf.gov.pl');
            self::fail('Host nie został odrzucony.');
        } catch (ConnectorException $exception) {
            self::assertSame('host_not_allowed', $exception->getMessage());
        }
        self::assertSame(0, $mock->getRequestsCount());
        self::assertSame('host_not_allowed', $this->connection->fetchOne('SELECT error_code FROM connector_call ORDER BY sequence DESC LIMIT 1'));
    }
    public function testPsr18RecordingPassesThroughAllowlistCounterAndLedger(): void
    {
        $mock = new \Symfony\Component\HttpClient\MockHttpClient(new \Symfony\Component\HttpClient\Response\MockResponse('fictional-psr18-response'));
        $clock = new \Symfony\Component\Clock\MockClock('2026-10-09T10:00:00Z');
        $http = \App\Tests\Support\ConnectorTestTools::http($this->connection, $clock, $mock)->withOptions(['extra' => ['connector_context' => new \App\Http\ConnectorRequestContext('dummy', 'default', 'call', 'test'),'rate_bucket' => 'dummy.call']]);
        $psr = new \Symfony\Component\HttpClient\Psr18Client($http);
        $response = $psr->sendRequest($psr->createRequest('GET', 'https://dummy.invalid/call'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $mock->getRequestsCount());
        self::assertSame(hash('sha256', 'fictional-psr18-response'), $this->connection->fetchOne('SELECT response_sha256 FROM connector_call ORDER BY sequence DESC LIMIT 1'));
    }
}
