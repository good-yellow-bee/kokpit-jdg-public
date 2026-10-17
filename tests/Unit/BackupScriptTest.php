<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Skrypt `backup.run` zapisuje głowę łańcucha `audit_log` w tagu snapshotu restic przed zrzutem bazy (D-79). */
final class BackupScriptTest extends TestCase
{
    public function testBackupTagsSnapshotWithAuditHeadTakenBeforeDump(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/docker/backup/run-backup.sh');
        self::assertIsString($script);
        $head = strpos($script, 'audit_head=$(psql -h db -U kokpit_backup -d kokpit -AtX -v ON_ERROR_STOP=1');
        $dump = strpos($script, 'pg_dump -h db -U kokpit_backup');
        $backup = strpos($script, 'restic backup --quiet --tag backup.run --tag "audit-head:$audit_head"');
        self::assertIsInt($head);
        self::assertIsInt($dump);
        self::assertIsInt($backup);
        self::assertLessThan($dump, $head);
        self::assertLessThan($backup, $dump);
        self::assertStringContainsString('[[ "$audit_head" =~ ^[0-9]+:[0-9a-f]{64}$ ]]', $script);
    }
}
