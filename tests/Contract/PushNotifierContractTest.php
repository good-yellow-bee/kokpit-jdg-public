<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Connector\Push\PushNotifier;
use App\Security\SecretStore;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ConnectorTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PushNotifierContractTest extends DatabaseTestCase
{
    public function testFictionalPushUsesTokenAndMinimalPayloadWithCallLog(): void
    {
        $mock = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('http://ntfy/kokpit', $url);
            self::assertSame('connector_failure: 3; /', $options['body']);
            self::assertContains('Authorization: Bearer fictional-push-credential', $options['headers']);
            self::assertSame(0, $options['max_redirects']);
            return new MockResponse('{}');
        });
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        $notifier = new PushNotifier(ConnectorTestTools::http($this->connection, new MockClock('2026-10-09T10:00:00Z'), $mock), $secrets, ConnectorTestTools::configuration());
        $notifier->send('connector_failure', 3, '/');
        self::assertSame(1, $mock->getRequestsCount());
        $row = $this->connection->fetchAssociative("SELECT * FROM connector_call WHERE connector='push' ORDER BY sequence DESC LIMIT 1");
        self::assertIsArray($row);
        self::assertSame(hash('sha256', 'connector_failure: 3; /'), $row['request_sha256']);
        self::assertSame('send', $row['operation']);
        self::assertStringNotContainsString('fictional-push-credential', json_encode($row, JSON_THROW_ON_ERROR));
    }
}
