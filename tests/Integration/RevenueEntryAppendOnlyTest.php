<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Exception\DriverException;
use Symfony\Component\Uid\Uuid;

final class RevenueEntryAppendOnlyTest extends DatabaseTestCase
{
    public function testApplicationRoleCannotUpdateOrDeleteEntries(): void
    {
        $id = (string) Uuid::v7();
        $this->connection->insert('revenue_entry', ['id' => $id,'environment' => 'test','year' => 2026,'lp' => 1,'entry_date' => '2026-11-05','revenue_date' => '2026-10-31','document_number' => 'FICTIONAL/2026/10/1','amount_pln' => '4270.00','rate_pct' => '12.00','source' => 'import','kind' => 'regular','recorded_at' => '2026-11-05T10:00:00Z']);
        foreach (['UPDATE revenue_entry SET amount_pln=1 WHERE id=?', 'DELETE FROM revenue_entry WHERE id=?'] as $statement) {
            $this->connection->executeStatement('SAVEPOINT append_only');
            try {
                $this->connection->executeStatement($statement, [$id]);
                self::fail('Ewidencja musi odrzucić ' . $statement);
            } catch (DriverException $exception) {
                // 42501: rola aplikacji nie ma UPDATE ani DELETE; trigger to druga bariera.
                self::assertContains($exception->getSQLState(), ['42501', 'P0001']);
            }
            $this->connection->executeStatement('ROLLBACK TO SAVEPOINT append_only');
        }
        self::assertSame('4270.00', $this->connection->fetchOne('SELECT amount_pln FROM revenue_entry WHERE id=?', [$id]));
    }
    public function testInvariantsRejectRowsOutsideTheirYear(): void
    {
        $this->expectException(DriverException::class);
        $this->connection->insert('revenue_entry', ['id' => (string) Uuid::v7(),'environment' => 'test','year' => 2026,'lp' => 1,'entry_date' => '2027-01-15','revenue_date' => '2027-01-02','document_number' => 'FICTIONAL/2027/01/1','amount_pln' => '1.00','rate_pct' => '12.00','source' => 'import','kind' => 'regular','recorded_at' => '2027-01-15T10:00:00Z']);
    }
}
