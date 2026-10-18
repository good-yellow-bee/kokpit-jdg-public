<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Audit\AuditLog;
use App\Command\RecordRestoreCommand;
use App\Command\RestoreCheckCommand;
use App\Ops\RestoreCheck;
use App\Security\Redactor;
use App\Storage\FileStore;
use App\Tests\Support\ApprovalTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore;

/** Test odtworzenia jednym poleceniem (D-89): raport `app:ops:restore-check`, liczby z PROD i wpis `ops.restored`. Dane fikcyjne. */
final class RestoreCheckTest extends DatabaseTestCase
{
    private const FILES = '/tmp/kokpit-restore-check-test';

    private MockClock $clock;
    private AuditLog $audit;
    private FileStore $files;
    private RestoreCheck $check;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-18T06:00:00Z');
        $this->audit = new AuditLog($this->connection, $this->clock, new Redactor());
        $this->files = new FileStore($this->connection, $this->clock, new LockFactory(new DoctrineDbalPostgreSqlStore($this->connection)), $this->audit, self::FILES);
        $this->check = new RestoreCheck($this->connection, $this->files, $this->audit, $this->clock);
        // Pliki innych testów nie leżą w katalogu tego testu; w transakcji testu są usunięte.
        $this->connection->executeStatement('UPDATE stored_file SET deleted_at = ? WHERE deleted_at IS NULL', [$this->clock->now()->format('c')]);
    }

    public function testSnapshotsPickRestoredAndOldestWithAuditHeadTags(): void
    {
        $old = str_repeat('a', 64);
        $new = str_repeat('b', 64);
        $json = json_encode([
            ['id' => $new, 'short_id' => 'bbbbbbbb', 'time' => '2026-10-18T02:30:05.123456789+02:00', 'tags' => ['backup.run', 'audit-head:7:' . str_repeat('c', 64)]],
            ['id' => $old, 'short_id' => 'aaaaaaaa', 'time' => '2026-09-01T02:30:01.5+02:00', 'tags' => ['backup.run']],
        ], JSON_THROW_ON_ERROR);
        $snapshots = RestoreCheck::snapshots($json, $new);
        self::assertSame(['id' => $new, 'time' => '2026-10-18T00:30:05Z', 'anchor' => 'audit-head:7:' . str_repeat('c', 64)], $snapshots['restored']);
        self::assertSame(['id' => $old, 'time' => '2026-09-01T00:30:01Z', 'anchor' => null], $snapshots['oldest']);
        $this->expectException(\InvalidArgumentException::class);
        RestoreCheck::snapshots($json, str_repeat('d', 64));
    }

    public function testCountsUseWarsawMonthWindowOfEachTable(): void
    {
        $before = $this->check->counts('2026-09');
        ApprovalTestTools::insert($this->connection, 'pending', new \DateTimeImmutable('2026-08-31T22:30:00Z'));
        ApprovalTestTools::insert($this->connection, 'pending', new \DateTimeImmutable('2026-09-30T22:30:00Z'));
        ApprovalTestTools::insert($this->connection, 'pending', new \DateTimeImmutable('2026-08-31T21:59:00Z'));
        $after = $this->check->counts('2026-09');
        // 1 września 00:30 w Warszawie liczy się do września, 1 października 00:30 już nie.
        self::assertSame($before['approval'] + 1, $after['approval']);
        self::assertSame($before['filing'], $after['filing']);
        self::assertSame('2026-09', $this->check->previousMonth());
        self::assertSame(['kind' => RestoreCheck::COUNTS_KIND, 'month' => '2026-09', 'counts' => $after], $this->check->countsDocument('2026-09'));
        $this->expectException(\InvalidArgumentException::class);
        $this->check->counts('2026-13');
    }

    public function testReportPassesOnlyWithMatchingFilesAnchorsAndCounts(): void
    {
        $this->audit->append('ops.deployed', ['fictional' => true]);
        $oldest = 'audit-head:' . $this->audit->head();
        $this->files->put('<fikcyjny/>', 'test', 'evidence', 'application/xml', 'filing', '01890000-0000-7000-8000-000000000001', 2026);
        $restored = 'audit-head:' . $this->audit->head();
        $snapshots = ['restored' => ['id' => str_repeat('b', 64), 'time' => '2026-10-18T00:30:05Z', 'anchor' => $restored], 'oldest' => ['id' => str_repeat('a', 64), 'time' => '2026-09-01T00:30:01Z', 'anchor' => $oldest]];
        $counts = $this->check->counts('2026-09');

        $report = $this->check->report($snapshots, ['month' => '2026-09', 'counts' => $counts]);
        self::assertTrue($report['passed']);
        self::assertSame(['month' => '2026-09', 'restored' => $counts, 'expected' => $counts, 'matches' => true], $report['counts']);
        self::assertSame(['checked' => 1, 'failed' => 0], $report['files']);

        $other = $counts;
        ++$other['revenue_entry'];
        self::assertFalse($this->check->report($snapshots, ['month' => '2026-09', 'counts' => $other])['passed']);
        $untagged = $snapshots;
        $untagged['oldest']['anchor'] = null;
        self::assertFalse($this->check->report($untagged, null)['passed']);
        $foreign = $snapshots;
        $foreign['restored']['anchor'] = 'audit-head:1:' . str_repeat('f', 64);
        self::assertFalse($this->check->report($foreign, null)['passed']);
        self::assertNull($this->check->report($snapshots, null)['counts']['matches']);
    }

    public function testCommandsPrintCountsCheckCopyAndRecordOpsRestored(): void
    {
        $this->audit->append('ops.deployed', ['fictional' => true]);
        $anchor = 'audit-head:' . $this->audit->head();
        $id = str_repeat('e', 64);
        $snapshots = tempnam(sys_get_temp_dir(), 'snapshots');
        $expected = tempnam(sys_get_temp_dir(), 'counts');
        $report = tempnam(sys_get_temp_dir(), 'report');
        self::assertIsString($snapshots);
        self::assertIsString($expected);
        self::assertIsString($report);
        file_put_contents($snapshots, json_encode([['id' => $id, 'short_id' => 'eeeeeeee', 'time' => '2026-10-18T02:30:05+02:00', 'tags' => ['backup.run', $anchor]]], JSON_THROW_ON_ERROR));

        $counts = new CommandTester(new RestoreCheckCommand($this->check));
        self::assertSame(0, $counts->execute(['--counts-only' => true]));
        file_put_contents($expected, $counts->getDisplay());
        self::assertSame('2026-09', RestoreCheck::parseCounts($counts->getDisplay())['month']);

        $check = new CommandTester(new RestoreCheckCommand($this->check));
        self::assertSame(0, $check->execute(['--snapshots' => $snapshots, '--snapshot' => $id, '--expected' => $expected], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('Test odtworzenia: pass.', $check->getErrorOutput());
        file_put_contents($report, $check->getDisplay());
        self::assertSame(2, (new CommandTester(new RestoreCheckCommand($this->check)))->execute(['--snapshots' => $snapshots]));

        $record = new CommandTester(new RecordRestoreCommand($this->audit));
        self::assertSame(0, $record->execute(['report' => $report]));
        $details = $this->connection->fetchOne("SELECT details FROM audit_log WHERE action = 'ops.restored' ORDER BY sequence DESC LIMIT 1");
        self::assertIsString($details);
        $entry = json_decode($details, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($entry);
        self::assertTrue($entry['passed']);
        self::assertSame($id, $entry['snapshot_id']);
        self::assertSame(['oldest' => true, 'restored' => true], $entry['anchors']);
        self::assertTrue($entry['counts_match']);
        self::assertTrue($this->audit->verify());

        file_put_contents($report, '{"kind":"other"}');
        self::assertSame(2, (new CommandTester(new RecordRestoreCommand($this->audit)))->execute(['report' => $report]));
        array_map('unlink', [$snapshots, $expected, $report]);
    }
}
