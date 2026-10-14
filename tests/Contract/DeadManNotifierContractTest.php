<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Audit\AuditLog;
use App\Config\ConnectorConfiguration;
use App\Connector\DeadMan\DeadManNotifier;
use App\Security\SecretStore;
use App\Security\Vault;
use App\Security\Redactor;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ConnectorTestTools;
use Symfony\Bundle\FrameworkBundle\Secrets\AbstractVault;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore;

final class DeadManNotifierContractTest extends DatabaseTestCase
{
    public function testOnlyProductionDeploymentPingsAndSecretPathIsNeverPersisted(): void
    {
        $clock = new MockClock('2026-10-09T10:00:00Z');
        $redactor = new Redactor();
        $vault = $this->createStub(AbstractVault::class);
        $vault->method('reveal')->willReturn('https://hc-ping.com/fictional-heartbeat-secret');
        $secrets = new SecretStore(new Vault($vault, $redactor, 'prod'), $this->connection, new LockFactory(new DoctrineDbalPostgreSqlStore($this->connection)), $clock, $redactor, new AuditLog($this->connection, $clock, $redactor), 'prod');
        $mock = new MockHttpClient(new MockResponse('fictional ok'));
        $http = ConnectorTestTools::http($this->connection, $clock, $mock, $redactor);
        foreach (['dev','restore','prod'] as $deployment) {
            $config = new ConnectorConfiguration($deployment === 'dev' ? 'test' : 'prod', $deployment, $deployment !== 'restore', 'test', 'test', 'test', 'http://ntfy/kokpit', 'smtp.example.invalid', 'hc-ping.com', []);
            (new DeadManNotifier($http, $secrets, $config))->ping();
        }
        self::assertSame(1, $mock->getRequestsCount());
        $row = $this->connection->fetchAssociative("SELECT path,host FROM connector_call WHERE connector='deadman'");
        self::assertIsArray($row);
        self::assertSame('/{heartbeat}', $row['path']);
        self::assertStringNotContainsString('fictional-heartbeat-secret', json_encode($row, JSON_THROW_ON_ERROR));
    }
}
