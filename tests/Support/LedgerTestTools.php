<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Audit\AuditLog;
use App\Ledger\JpkEwpSchema;
use App\Ledger\LedgerFileParser;
use App\Ledger\LedgerImporter;
use App\Security\Redactor;
use App\Storage\FileStore;
use App\Time\BusinessDate;
use App\Xml\XsdCatalog;
use App\Xml\XsdDictionary;
use App\Xml\XsdValidator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore;

final class LedgerTestTools
{
    public const FIXTURES = __DIR__ . '/../Fixtures/Jpk';
    public static function importer(Connection $db, MockClock $clock): LedgerImporter
    {
        $audit = new AuditLog($db, $clock, new Redactor());
        $files = new FileStore($db, $clock, new LockFactory(new DoctrineDbalPostgreSqlStore($db)), $audit, TestFiles::root('ledger'));
        return new LedgerImporter($db, $clock, new BusinessDate($clock), self::parser(), $files, $audit, ConnectorTestTools::configuration(false));
    }
    public static function parser(): LedgerFileParser
    {
        $catalog = XsdCatalog::default();
        return new LedgerFileParser(new XsdValidator($catalog), new JpkEwpSchema(new XsdDictionary($catalog)));
    }
    public static function fixture(string $name): string
    {
        $bytes = file_get_contents(self::FIXTURES . '/' . $name);
        if ($bytes === false) {
            throw new \RuntimeException('Brak fixture ' . $name);
        }
        return $bytes;
    }
    /** @return list<array<string,mixed>> */
    public static function rows(Connection $db, int $year = 2026): array
    {
        return $db->fetchAllAssociative("SELECT year,lp,entry_date AS k_2,revenue_date AS k_3,document_number AS k_4,ksef_number AS k_5,counterparty_country AS k_6,counterparty_tax_id AS k_7,amount_pln AS k_8,rate_pct AS k_9,remarks AS k_10,source,kind FROM revenue_entry WHERE environment='test' AND year=? ORDER BY lp", [$year]);
    }
    /**
     * Blokada roku ewidencji trzymana przez drugie połączenie: `$write` musi na nią czekać i paść po `lock_timeout`.
     */
    public static function assertWaitsForYearLock(Connection $db, int $year, callable $write): void
    {
        $other = DriverManager::getConnection($db->getParams());
        $other->executeQuery('SELECT pg_advisory_lock(?, ?)', [\App\Ledger\RevenueEntryWriter::LOCK_NAMESPACE, $year]);
        try {
            $db->executeStatement("SET LOCAL lock_timeout = '200ms'");
            $write();
            \PHPUnit\Framework\Assert::fail('Zapis nie czekał na blokadę roku.');
        } catch (DriverException $exception) {
            \PHPUnit\Framework\Assert::assertStringContainsString('lock timeout', $exception->getMessage());
        } finally {
            $other->executeQuery('SELECT pg_advisory_unlock_all()');
            $other->close();
        }
    }
}
