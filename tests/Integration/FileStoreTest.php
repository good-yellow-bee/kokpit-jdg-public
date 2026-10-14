<?php

namespace App\Tests\Integration;

use App\Audit\AuditLog;
use App\Security\Redactor;
use App\Storage\FileStore;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Console\Tester\CommandTester;
use App\Command\FilesVerifyCommand;

class FileStoreTest extends DatabaseTestCase
{
    protected function store(MockClock $clock): FileStore
    {
        return new FileStore($this->connection, $clock, new LockFactory(new DoctrineDbalPostgreSqlStore($this->connection)), new AuditLog($this->connection, $clock, new Redactor()), '/app/var/test-files/' . self::class);
    }
    public function testDeduplicatesOwnersAndDetectsChangedBytes(): void
    {
        $clock = new MockClock('2026-10-09T10:00:00Z');
        $store = $this->store($clock);
        $owner = Uuid::v7()->toRfc4122();
        $bytes = 'fictional file ' . Uuid::v7();
        $id = $store->put($bytes, 'test', 'evidence', 'text/plain', 'app_user', $owner, 2026);
        self::assertSame($id, $store->put($bytes, 'test', 'evidence', 'text/plain', 'app_user', $owner, 2026));
        $other = $store->put($bytes, 'test', 'evidence', 'text/plain', 'app_user', Uuid::v7()->toRfc4122(), 2026);
        self::assertNotSame($id, $other);
        $rows = $this->connection->fetchAllAssociative('SELECT path FROM stored_file');
        self::assertCount(2, $rows);
        self::assertSame($rows[0]['path'], $rows[1]['path']);
        self::assertIsString($rows[0]['path']);
        $path = '/app/var/test-files/' . self::class . '/' . $rows[0]['path'];
        self::assertSame(0440, fileperms($path) & 0777);
        self::assertSame([], $store->verify()['failed']);
        chmod($path, 0640);
        file_put_contents($path, 'fictional tampering');
        $command = new CommandTester(new FilesVerifyCommand($store));
        self::assertSame(1, $command->execute([]));
        self::assertCount(2, $store->verify()['failed']);
        unlink($path);
    }
    public function testDeduplicationAcrossYearAndKindValidation(): void
    {
        $clock = new MockClock('2026-12-31T22:00:00Z');
        $store = $this->store($clock);
        $bytes = 'fictional yearly ' . Uuid::v7();
        $store->put($bytes, 'test', 'evidence', 'text/plain', 'app_user', Uuid::v7()->toRfc4122(), 2026);
        $clock->sleep(7200);
        $store->put($bytes, 'test', 'evidence', 'text/plain', 'app_user', Uuid::v7()->toRfc4122(), 2026);
        self::assertSame(1, $this->connection->fetchOne('SELECT count(DISTINCT path) FROM stored_file'));
    }
}
