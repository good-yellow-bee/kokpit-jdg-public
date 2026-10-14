<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\LedgerImportRefused;
use App\Tests\Support\LedgerTestTools;
use Symfony\Component\Clock\MockClock;

final class LedgerImportTest extends DatabaseTestCase
{
    public function testImportIsIdempotentAndAppendsOnlyNewRows(): void
    {
        $clock = new MockClock('2026-11-05T09:00:00Z');
        $importer = LedgerTestTools::importer($this->connection, $clock);
        $first = $importer->import(LedgerTestTools::fixture('ledger-2026-jan-oct.csv'), 2026);
        self::assertSame([14, 14, 14], [$first->rowsInFile, $first->rowsAdded, $first->rowsInLedger]);
        $second = $importer->import(LedgerTestTools::fixture('ledger-2026-jan-oct.csv'), 2026);
        self::assertSame([0, 14, null], [$second->rowsAdded, $second->rowsInLedger, $second->evidenceSha256]);
        self::assertSame($first->ledgerSha256, $second->ledgerSha256);
        $clock->modify('+30 days');
        $third = $importer->import(LedgerTestTools::fixture('ledger-2026-jan-nov.csv'), 2026);
        self::assertSame([2, 16], [$third->rowsAdded, $third->rowsInLedger]);
        $rows = LedgerTestTools::rows($this->connection);
        self::assertSame(['2026-11-30','2026-11-30'], [$rows[14]['k_3'], $rows[15]['k_3']]);
        // Wiersze odtworzone bez K_2 dostały dzień pierwszego importu; ponowny import ich nie zmienia (D-23).
        self::assertSame('2026-11-05', $rows[3]['k_2']);
        self::assertSame('2026-10-12', $rows[0]['k_2']);
        self::assertSame(['import','regular'], [$rows[12]['source'], $rows[12]['kind']]);
        self::assertSame('-120.00', $rows[12]['k_8']);
        self::assertSame('8.50', $rows[10]['k_9']);
        self::assertSame('4120.00', $rows[4]['k_8']);
        self::assertSame(2, $this->connection->fetchOne("SELECT count(*) FROM stored_file WHERE kind='evidence' AND owner_table='revenue_entry'"));
        self::assertSame(2, $this->connection->fetchOne("SELECT count(*) FROM audit_log WHERE action='ledger.imported'"));
    }
    public function testImportReportsSumsPerMonthAndRate(): void
    {
        $result = LedgerTestTools::importer($this->connection, new MockClock('2026-12-05T09:00:00Z'))->import(LedgerTestTools::fixture('ledger-2026-jan-nov.csv'), 2026);
        $lines = [];
        foreach ($result->sums as $month => $rates) {
            foreach ($rates as $rate => $sum) {
                $lines[] = sprintf('%s;%s;%d;%s', $month, $rate, $sum['rows'], $sum['sum']);
            }
        }
        $golden = array_values(array_filter(explode("\n", LedgerTestTools::fixture('golden/ledger-import-sums.txt')), static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#')));
        self::assertSame($golden, $lines);
    }
    public function testXmlImportKeepsOriginalEntryDates(): void
    {
        $result = LedgerTestTools::importer($this->connection, new MockClock('2026-11-05T09:00:00Z'))->import(LedgerTestTools::fixture('ledger-2026-jpk-ewp.xml'), 2026);
        self::assertSame(4, $result->rowsAdded);
        $rows = LedgerTestTools::rows($this->connection);
        self::assertSame(['2026-02-10','2026-03-12','2026-04-14','2026-05-11'], array_column($rows, 'k_2'));
        self::assertSame(['12.00','8.50','12.00','12.00'], array_column($rows, 'k_9'));
        self::assertSame([null, null, 'dane fictional', 'korekta FICTIONAL/2026/01/1'], array_column($rows, 'k_10'));
        self::assertSame(1, $this->connection->fetchOne("SELECT count(*) FROM stored_file WHERE kind='evidence' AND media_type='application/xml'"));
    }
    public function testChangedKnownRowRefusesWholeFile(): void
    {
        $importer = LedgerTestTools::importer($this->connection, new MockClock('2026-11-05T09:00:00Z'));
        $importer->import(LedgerTestTools::fixture('ledger-2026-jan-oct.csv'), 2026);
        $edited = str_replace('4100.00;12;', '4101.00;12;', LedgerTestTools::fixture('ledger-2026-jan-nov.csv'));
        try {
            $importer->import($edited, 2026);
            self::fail('Zmieniony znany wiersz musi dać odmowę.');
        } catch (LedgerImportRefused $refused) {
            self::assertStringContainsString('K_1 4: K_8', implode("\n", $refused->problems));
        }
        self::assertCount(14, LedgerTestTools::rows($this->connection));
    }
}
