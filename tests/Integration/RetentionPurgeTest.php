<?php

namespace App\Tests\Integration;

use App\Tests\Support\TestFiles;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class RetentionPurgeTest extends DatabaseTestCase
{
    protected function store(MockClock $clock): \App\Storage\FileStore
    {
        return new \App\Storage\FileStore($this->connection, $clock, new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore($this->connection)), new \App\Audit\AuditLog($this->connection, $clock, new \App\Security\Redactor()), TestFiles::root(self::class));
    }

    public function testExpiredFileAndLegalHoldSharedReference(): void
    {
        $clock = new MockClock('2026-10-09T10:00:00Z');
        $store = $this->store($clock);
        $bytes = 'fictional hold ' . Uuid::v7();
        $expired = $store->put($bytes, 'test', 'evidence', 'text/plain', 'app_user', Uuid::v7()->toRfc4122(), 2020);
        $held = $store->put($bytes, 'test', 'evidence', 'text/plain', 'app_user', Uuid::v7()->toRfc4122(), 2020, true);
        self::assertSame(1, $store->purge());
        self::assertNotNull($this->connection->fetchOne('SELECT deleted_at FROM stored_file WHERE id=?', [$expired]));
        self::assertNull($this->connection->fetchOne('SELECT deleted_at FROM stored_file WHERE id=?', [$held]));
        self::assertSame([], $store->verify()['failed']);
        self::assertSame(1, $this->connection->fetchOne("SELECT count(*) FROM audit_log WHERE action='file.deleted'"));
        self::assertSame(0, $store->purge());
    }
}
