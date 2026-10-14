<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Connector\Mail\MailNotifier;
use App\Connector\Mail\GuardedMailTransport;
use App\Config\ConnectorHosts;
use App\Http\HostAllowlist;
use App\Http\ConnectorCallRecorder;
use App\Rate\RateCounter;
use App\Security\SecretStore;
use App\Time\BusinessDate;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ConnectorTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use App\Tests\Support\MemoryMailTransport;

final class MailNotifierContractTest extends DatabaseTestCase
{
    public function testInMemorySmtpHasMinimalContentAndOneCallLog(): void
    {
        $configuration = ConnectorTestTools::configuration();
        $clock = new MockClock('2026-10-09T10:00:00Z');
        $dates = new BusinessDate($clock);
        $memory = new MemoryMailTransport();
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        $transport = new GuardedMailTransport($configuration, $secrets, new HostAllowlist(ConnectorHosts::HOSTS, $configuration, new MockHttpClient()), new ConnectorCallRecorder($this->connection, $clock), new RateCounter($this->connection, $clock, $dates, []), $clock, $dates, $memory);
        (new MailNotifier($transport, 'kokpit@example.invalid', 'owner@example.invalid'))->send('connector_failure', 2, '/');
        self::assertCount(1, $memory->getSent());
        self::assertStringContainsString('connector_failure: 2; /', $memory->getSent()[0]->toString());
        $row = $this->connection->fetchAssociative("SELECT * FROM connector_call WHERE connector='mail' ORDER BY sequence DESC LIMIT 1");
        self::assertIsArray($row);
        self::assertSame('smtp.send', $row['operation']);
        self::assertNull($row['http_method']);
        self::assertNull($row['http_status']);
        self::assertNull($row['path']);
        self::assertSame(hash('sha256', $memory->getSent()[0]->toString()), $row['request_sha256']);
    }
}
