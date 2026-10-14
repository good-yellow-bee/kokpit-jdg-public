<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Audit\AuditLog;
use App\Command\RecordDeployCommand;
use App\Config\ConnectorConfiguration;
use App\Security\Redactor;
use App\Tests\Support\ConnectorTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

final class RecordDeployTest extends DatabaseTestCase
{
    public function testDeployRecordsOnlyChangedTargetsOnce(): void
    {
        $before = $this->connection->fetchOne('SELECT max(sequence) FROM audit_log');
        self::assertTrue($before === null || is_int($before));
        $audit = new AuditLog($this->connection, new MockClock('2026-10-09T10:00:00Z'), new Redactor());
        $first = ConnectorTestTools::configuration(false);
        $second = new ConnectorConfiguration('test', 'dev', true, 'demo', 'test', 'test', $first->pushEndpoint, $first->mailHost, $first->deadmanHost, []);
        foreach ([$first,$second,$second] as $config) {
            $command = new CommandTester(new RecordDeployCommand($audit, $this->connection, $config));
            self::assertSame(0, $command->execute([]));
        }
        $actions = $this->connection->fetchFirstColumn('SELECT action FROM audit_log WHERE sequence>? ORDER BY sequence', [$before ?? 0]);
        self::assertSame(['ops.deployed','connector.target_changed','connector.enabled_changed','ops.deployed','ops.deployed'], $actions);
        self::assertTrue($audit->verify());
    }
}
