<?php

namespace App\Tests\Integration;

use App\Audit\AuditLog;
use App\Security\Redactor;
use Doctrine\DBAL\Exception;
use Symfony\Component\Clock\MockClock;

final class AuditLogTest extends DatabaseTestCase
{
    public function testLinearCanonicalChainAndMasking(): void
    {
        $redactor = new Redactor();
        $redactor->registerSecret('fictional-audit-secret');
        $audit = new AuditLog($this->connection, new MockClock('2026-10-09T10:00:00Z'), $redactor);
        $id = $audit->append('ops.deployed', ['token' => 'fictional-audit-secret','nested' => ['b' => 2,'a' => 1]]);
        $audit->append('credential.registered', ['kind' => 'dummy_token']);
        self::assertSame('2026-10-09T10:00:00+00:00', (new \Symfony\Component\Uid\UuidV7($id))->getDateTime()->format(DATE_ATOM));
        self::assertTrue($audit->verify());
        self::assertGreaterThanOrEqual(2, $this->connection->fetchOne('SELECT count(*) FROM audit_log'));
        self::assertStringNotContainsString('fictional-audit-secret', json_encode($this->connection->fetchAllAssociative('SELECT * FROM audit_log'), JSON_THROW_ON_ERROR));
    }
    public function testAppCannotMutateAuditOrDisableTrigger(): void
    {
        self::assertSame('kokpit_app', $this->connection->fetchOne('SELECT current_user'));
        foreach (['UPDATE audit_log SET action=\'ops.restored\'','DELETE FROM audit_log','TRUNCATE audit_log','ALTER TABLE audit_log DISABLE TRIGGER audit_log_immutable'] as $sql) {
            $this->connection->executeStatement('SAVEPOINT denied');
            try {
                $this->connection->executeStatement($sql);
                self::fail('Niedozwolona operacja działa.');
            } catch (Exception $exception) {
                self::assertStringContainsString('42501', $exception->getMessage());
            } finally {
                $this->connection->executeStatement('ROLLBACK TO SAVEPOINT denied');
            }
        }
    }
}
