<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Audit\AuditLog;
use App\Http\ConnectorRequestContext;
use App\Messenger\Message\ConnectorCallMessage;
use App\Messenger\RedactingTransportSerializer;
use App\Security\Redactor;
use App\Tests\Support\ConnectorTestTools;
use Monolog\Logger;
use Monolog\Handler\TestHandler;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as TransportConnection;

final class RedactorPersistenceTest extends DatabaseTestCase
{
    public function testSecretPeselAndJwtNeverReachLogsQueuesOrLedgers(): void
    {
        // Wyłącznie fikcyjne dane, wstrzykiwane celowo do każdego trwałego wyjścia.
        $secret = 'fictional-persistence-secret';
        $pesel = '11111111116';
        $jwt = 'eyJmaWN0aW9uYWwiOnRydWV9.e30.ZmljdGlvbmFs';
        $content = $secret . ' ' . $pesel . ' ' . $jwt;
        $redactor = new Redactor();
        $redactor->registerSecret($secret);
        $clock = new MockClock('2026-10-09T10:00:00Z');
        $handler = new TestHandler();
        $logger = new Logger('connector', [$handler], [$redactor]);
        $logger->error($content, ['Authorization' => 'Bearer ' . $secret,'payload' => $content]);
        (new AuditLog($this->connection, $clock, $redactor))->append('ops.deployed', ['fictional_payload' => $content]);
        $transport = new DoctrineTransport(new TransportConnection(['queue_name' => 'failed','auto_setup' => false], $this->connection), new RedactingTransportSerializer($redactor));
        $transport->send(new Envelope(new ConnectorCallMessage('heartbeat'), [ErrorDetailsStamp::create(new \RuntimeException($content))]));
        $mock = new MockHttpClient(new MockResponse($content, ['response_headers' => ['Authorization: Bearer ' . $secret]]));
        ConnectorTestTools::http($this->connection, $clock, $mock, $redactor)->connectorRequest(new ConnectorRequestContext('dummy', 'default', 'call', 'test'), 'dummy.call', 'POST', 'https://dummy.invalid/' . $secret, ['body' => $content,'headers' => ['Authorization' => 'Bearer ' . $secret]]);
        $persisted = json_encode([$handler->getRecords(),$this->connection->fetchAllAssociative('SELECT body,headers FROM messenger_messages'),$this->connection->fetchAllAssociative('SELECT * FROM connector_call'),$this->connection->fetchAllAssociative('SELECT details FROM audit_log')], JSON_THROW_ON_ERROR);
        foreach ([$secret,$pesel,$jwt] as $value) {
            self::assertStringNotContainsString($value, $persisted);
        }
        self::assertSame(hash('sha256', $content), $this->connection->fetchOne('SELECT request_sha256 FROM connector_call ORDER BY sequence DESC LIMIT 1'));
    }
}
