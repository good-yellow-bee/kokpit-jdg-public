<?php

namespace App\Tests\Integration;

use App\Audit\AuditLog;
use App\Command\AuditVerifyCommand;
use App\Security\Redactor;
use Doctrine\DBAL\Exception;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

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
    /** Kotwica z tagu snapshotu restic (D-79): pasuje tylko do swojego wpisu, a komenda zgłasza niezgodność. */
    public function testHeadAnchorsMatchOnlyTheirOwnChainEntry(): void
    {
        $audit = new AuditLog($this->connection, new MockClock('2026-10-17T00:30:00Z'), new Redactor());
        $audit->append('ops.deployed', ['fictional' => true]);
        $anchor = $audit->head();
        self::assertMatchesRegularExpression('/^[1-9][0-9]*:[0-9a-f]{64}$/', $anchor);
        $audit->append('credential.registered', ['kind' => 'dummy_token']);
        self::assertNotSame($anchor, $audit->head());
        self::assertTrue($audit->matchesAnchor($anchor));
        self::assertTrue($audit->matchesAnchor('audit-head:' . $anchor));
        self::assertTrue($audit->matchesAnchor('0:' . str_repeat('0', 64)));
        $sequence = explode(':', $anchor)[0];
        self::assertFalse($audit->matchesAnchor($sequence . ':' . str_repeat('a', 64)));
        self::assertFalse($audit->matchesAnchor('999999999999:' . hash('sha256', 'fikcyjny')));

        $tester = new CommandTester(new AuditVerifyCommand($audit));
        self::assertSame(Command::SUCCESS, $tester->execute(['--anchor' => ['audit-head:' . $anchor]]));
        self::assertStringContainsString(sprintf('Kotwica audit-head:%s: zgodna.', $anchor), $tester->getDisplay());
        self::assertStringContainsString('Głowa łańcucha: audit-head:' . $audit->head(), $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['--anchor' => ['audit-head:' . $anchor, $sequence . ':' . str_repeat('a', 64)]]));
        self::assertStringContainsString('niezgodna.', $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->execute(['--anchor' => ['audit-head:1:XYZ']]));
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
