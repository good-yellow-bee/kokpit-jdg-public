<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Rate\RateCounter;
use App\Rate\RateLimitExceededException;
use App\Http\ConnectorRequestContext;
use App\Time\BusinessDate;
use App\Tests\Support\ConnectorTestTools;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RateCounterTest extends DatabaseTestCase
{
    public function testDailyLimitStopsIncrementAndCarriesBlockAcrossMidnight(): void
    {
        $clock = new MockClock('2030-03-30T22:59:00Z');
        $dates = new BusinessDate($clock);
        $rates = new RateCounter($this->connection, $clock, $dates, ['dummy.call' => 2]);
        self::assertSame(1, $rates->consume('test', 'dummy', 'dummy.call')->count);
        self::assertSame(2, $rates->consume('test', 'dummy', 'dummy.call')->count);
        try {
            $rates->consume('test', 'dummy', 'dummy.call');
            self::fail('Brak odroczenia.');
        } catch (RateLimitExceededException $exception) {
            self::assertEquals($dates->nextMidnight(), $exception->blockedUntil);
        }
        $rates->blockUntil('test', 'dummy', 'dummy.call', $dates->midnight(), $clock->now()->modify('+2 hours'));
        $clock->sleep(120);
        $this->expectException(RateLimitExceededException::class);
        $rates->consume('test', 'dummy', 'dummy.call');
    }
    public function testRetryAfterMakesNoSecondRequestUntilUnblocked(): void
    {
        $clock = new MockClock('2030-03-30T10:00:00Z');
        $mock = new MockHttpClient([new MockResponse('{}', ['http_code' => 429,'response_headers' => ['retry-after: 120']]),new MockResponse('{}')]);
        $http = ConnectorTestTools::http($this->connection, $clock, $mock);
        $context = new ConnectorRequestContext('dummy', 'default', 'call', 'test');
        $http->connectorRequest($context, 'dummy.call', 'GET', 'https://dummy.invalid/call');
        self::assertSame(120, $this->connection->fetchOne('SELECT retry_after_s FROM connector_call ORDER BY sequence DESC LIMIT 1'));
        try {
            $http->connectorRequest($context, 'dummy.call', 'GET', 'https://dummy.invalid/call');
            self::fail('Brak blokady.');
        } catch (RateLimitExceededException) {
            self::assertSame(1, $mock->getRequestsCount());
        }
        $clock->sleep(120);
        $http->connectorRequest($context, 'dummy.call', 'GET', 'https://dummy.invalid/call');
        self::assertSame(2, $mock->getRequestsCount());
    }
    public function testConcurrentProcessesIncrementExactly(): void
    {
        $params = $this->connection->getParams();
        $this->connection->rollBack();
        $this->connection->close();
        $children = [];
        try {
            for ($worker = 0;$worker < 4;$worker++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Nie można uruchomić procesu.');
                }
                if ($pid === 0) {
                    $db = DriverManager::getConnection($params);
                    $clock = new MockClock('2031-01-02T10:00:00Z');
                    $rates = new RateCounter($db, $clock, new BusinessDate($clock), []);
                    for ($i = 0;$i < 25;$i++) {
                        $rates->consume('test', 'dummy', 'dummy.call');
                    } $db->close();
                    exit(0);
                }
                $children[] = $pid;
            }
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                self::assertSame(0, pcntl_wexitstatus($status));
            }
            self::assertSame(100, $this->connection->fetchOne("SELECT count FROM rate_counter WHERE window_start='2031-01-01T23:00:00Z' AND connector='dummy' AND bucket='dummy.call'"));
        } finally {
            $this->connection->executeStatement("DELETE FROM rate_counter WHERE window_start='2031-01-01T23:00:00Z' AND connector='dummy' AND bucket='dummy.call'");
        }
    }
}
