<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Health\HealthChecker;
use App\Health\HealthResources;
use App\Scheduler\HeartbeatStore;
use App\Tests\Support\ApprovalTestTools;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class HealthCheckTest extends WebTestCase
{
    /** @return iterable<string,array{string}> */
    public static function failures(): iterable
    {
        foreach (['healthy','database','reads_queue','official_writes_queue','notifications_queue','scheduler_heartbeat','failed_messages','credentials','approval_unknown','disk_space','backup','tls_certificate'] as $failure) {
            yield $failure => [$failure];
        }
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testEachHealthFailureReturns503AndHealthyReturns200(string $failure): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $db->beginTransaction();
        $clock = new MockClock('2026-10-09T10:00:00Z');
        try {

            $db->executeStatement('DELETE FROM messenger_messages');
            $db->executeStatement("UPDATE credential_state SET status='valid'");
            if (str_ends_with($failure, '_queue') || $failure === 'failed_messages') {
                $queue = $failure === 'failed_messages' ? 'failed' : substr($failure, 0, -6);
                $db->insert('messenger_messages', ['body' => '{"id":"heartbeat"}','headers' => '{}','queue_name' => $queue,'created_at' => $clock->now()->modify('-1 hour')->format('Y-m-d H:i:s'),'available_at' => $clock->now()->format('Y-m-d H:i:s')]);
            }
            if ($failure === 'credentials') {
                $db->executeStatement("INSERT INTO credential_state (id,environment,kind,status,secret_name,created_at,updated_at) VALUES (?, 'test','dummy_token','expired','DUMMY_TEST_TOKEN',?,?) ON CONFLICT (environment,kind) DO UPDATE SET status='expired'", [(string) \Symfony\Component\Uid\Uuid::v7(),$clock->now()->format('c'),$clock->now()->format('c')]);
            }
            if ($failure === 'approval_unknown') {
                ApprovalTestTools::insert($db, 'unknown', $clock->now()->modify('-61 minutes'));
            }
            $heartbeat = new HeartbeatStore(new ArrayAdapter());
            $heartbeat->record($clock->now()->modify($failure === 'scheduler_heartbeat' ? '-601 seconds' : '-1 second'));
            $resources = $this->createStub(HealthResources::class);
            $resources->method('diskFreePercent')->willReturn($failure === 'disk_space' ? 19 : 20);
            $resources->method('backupModifiedAt')->willReturn($clock->now()->getTimestamp() - ($failure === 'backup' ? 93601 : 93600));
            $resources->method('certificateValidUntil')->willReturn($clock->now()->getTimestamp() + 14 * 86400 - ($failure === 'tls_certificate' ? 1 : 0));
            $connection = $db;
            if ($failure === 'database') {
                $connection = $this->createStub(Connection::class);
                $connection->method('fetchOne')->willThrowException(new \Doctrine\DBAL\Exception\InvalidArgumentException('fikcyjna awaria'));
            }
            self::getContainer()->set(HealthChecker::class, new HealthChecker($connection, $clock, 'prod', $heartbeat, $resources, ['reads' => 30,'official_writes' => 5,'notifications' => 30], 600, 93600, 20, 14));
            $client->request('GET', '/health');
            self::assertResponseStatusCodeSame($failure === 'healthy' ? 200 : 503);
            $content = $client->getResponse()->getContent();
            self::assertIsString($content);
            $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            self::assertIsArray($data['checks']);
            if ($failure !== 'healthy') {
                self::assertSame('error', $data['checks'][$failure]);
            }
            self::assertStringNotContainsString('dummy_token', $content);
            self::assertStringNotContainsString('DUMMY_TEST_TOKEN', $content);
        } finally {
            while ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
    }
}
