<?php

namespace App\Tests\Integration;

use App\Security\SecretStore;
use App\Security\Vault;
use App\Security\Redactor;
use App\Audit\AuditLog;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore;

final class SecretStoreTest extends DatabaseTestCase
{
    public function testVaultAndEncryptedRoundTrip(): void
    {
        $vault = self::getContainer()->get(Vault::class);
        self::assertInstanceOf(Vault::class, $vault);
        $clock = new MockClock('2026-10-09T10:00:00+00:00');
        $redactor = new Redactor();
        $audit = new AuditLog($this->connection, $clock, $redactor);
        $secrets = new SecretStore($vault, $this->connection, new LockFactory(new DoctrineDbalPostgreSqlStore($this->connection)), $clock, $redactor, $audit, 'test');
        self::assertSame('fictional-dummy-credential', $secrets->get('dummy_token', 'test'));
        $secrets->put('freshbooks_oauth', 'test', 'fictional-refresh-secret', $clock->now()->modify('+90 days'));
        self::assertSame('fictional-refresh-secret', $secrets->get('freshbooks_oauth', 'test'));
        $row = $this->connection->fetchAssociative("SELECT encode(ciphertext,'hex') AS ciphertext,encode(nonce,'hex') AS nonce FROM credential_state WHERE kind='freshbooks_oauth' AND environment='test'");
        self::assertIsArray($row);
        self::assertNotSame(bin2hex('fictional-refresh-secret'), $row['ciphertext']);
        self::assertIsString($row['nonce']);
        self::assertSame(48, strlen($row['nonce']));
        self::assertStringNotContainsString('fictional-refresh-secret', $redactor->text('fictional-refresh-secret'));
    }
    public function testStaticRegistrationContainsNoValueAndIsAudited(): void
    {
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        $clock = self::getContainer()->get(\Symfony\Component\Clock\ClockInterface::class);
        self::assertInstanceOf(\Symfony\Component\Clock\ClockInterface::class, $clock);
        $before = $this->connection->fetchOne("SELECT count(*) FROM audit_log WHERE action='credential.registered'");
        self::assertIsInt($before);
        $secrets->register('dummy_token', 'test', $clock->now()->modify('+90 days'));
        $row = $this->connection->fetchAssociative("SELECT status,ciphertext,secret_name FROM credential_state WHERE kind='dummy_token' AND environment='test'");
        self::assertIsArray($row);
        self::assertSame('valid', $row['status']);
        self::assertNull($row['ciphertext']);
        self::assertSame('DUMMY_TEST_TOKEN', $row['secret_name']);
        self::assertSame($before + 1, $this->connection->fetchOne("SELECT count(*) FROM audit_log WHERE action='credential.registered'"));
    }
    public function testStaticPutIsRejected(): void
    {
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        $this->expectException(\InvalidArgumentException::class);
        $secrets->put('dummy_token', 'test', 'fictional', new \DateTimeImmutable('2090-01-01'));
    }
    public function testExpiredRotatingCredentialIsRefusedEvenBeforeStatusTaskRuns(): void
    {
        $vault = self::getContainer()->get(Vault::class);
        self::assertInstanceOf(Vault::class, $vault);
        $clock = new MockClock('2026-10-09T10:00:00Z');
        $redactor = new Redactor();
        $store = new SecretStore($vault, $this->connection, new LockFactory(new DoctrineDbalPostgreSqlStore($this->connection)), $clock, $redactor, new AuditLog($this->connection, $clock, $redactor), 'test');
        $store->put('freshbooks_oauth', 'test', 'fictional-expired-secret', $clock->now()->modify('+1 hour'));
        $clock->sleep(3601);
        $this->expectException(\RuntimeException::class);
        $store->get('freshbooks_oauth', 'test');
    }
}
