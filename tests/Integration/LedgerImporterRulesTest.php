<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\LedgerImportRefused;
use App\Tests\Support\LedgerTestTools;
use Symfony\Component\Clock\MockClock;

/** Import ewidencji: zakres roku, różnice znanych wierszy, dowód, zrzut i blokada roku. Dane fikcyjne. */
final class LedgerImporterRulesTest extends DatabaseTestCase
{
    private const HEADER = "K_1;K_2;K_3;K_4;K_5;K_6;K_7;K_8;K_9;K_10\n";

    public function testYearRangeEndsWith2100(): void
    {
        $importer = LedgerTestTools::importer($this->db(), new MockClock('2026-11-05T09:00:00Z'));
        self::assertSame(1, $importer->import(self::HEADER . "1;2100-01-10;2100-01-05;FICTIONAL/2100/1;;;;10.00;12;\n", 2100)->rowsAdded);
        self::assertSame(['rok 2101 poza zakresem ewidencji w programie (od 2026)'], self::refused(static fn() => $importer->import(self::HEADER . "1;;2101-01-05;FICTIONAL/2101/1;;;;10.00;12;\n", 2101)));
    }

    public function testKnownRowDifferenceNamesFileAndLedgerValues(): void
    {
        $importer = LedgerTestTools::importer($this->db(), new MockClock('2026-11-05T09:00:00Z'));
        $csv = LedgerTestTools::fixture('ledger-2026-jan-oct.csv');
        $importer->import($csv, 2026);
        $edited = str_replace('4;;2026-01-31;FICTIONAL/2026/01/1;;DE;DE999999999;4100.00;12;odtworzony, dane fictional', '4;;2026-01-31;FICTIONAL/2026/01/1;;DE;DE999999999;4101.00;12;', $csv);
        self::assertNotSame($csv, $edited);
        self::assertSame(['K_1 4: K_8 w pliku „4101.00”, w ewidencji „4100.00”', 'K_1 4: K_10 w pliku „”, w ewidencji „odtworzony, dane fictional”'], self::refused(static fn() => $importer->import($edited, 2026)));
        // Puste K_2 w pliku nie jest różnicą: ewidencja trzyma dzień pierwszego importu.
        $withoutK2 = (string) preg_replace('/^(\d+);[0-9-]*;/m', '$1;;', $csv);
        self::assertSame(0, $importer->import($withoutK2, 2026)->rowsAdded);
    }

    public function testEvidenceRetentionAndAuditFormatFollowFileType(): void
    {
        $clock = new MockClock('2026-11-05T09:00:00Z');
        LedgerTestTools::importer($this->db(), $clock)->import(LedgerTestTools::fixture('ledger-2026-jpk-ewp.xml'), 2026);
        LedgerTestTools::importer($this->db(), $clock)->import(self::HEADER . "1;;2027-01-05;FICTIONAL/2027/1;;;;10.00;12;\n", 2027);
        $files = $this->db()->fetchAllAssociative("SELECT media_type, retention_until FROM stored_file WHERE kind='evidence' AND owner_table='revenue_entry' ORDER BY retention_until");
        self::assertSame([['media_type' => 'application/xml','retention_until' => '2032-12-31'], ['media_type' => 'text/csv','retention_until' => '2033-12-31']], $files);
        $formats = $this->db()->fetchFirstColumn("SELECT details->>'format' FROM audit_log WHERE action='ledger.imported' ORDER BY sequence");
        self::assertSame(['jpk_ewp_xml', 'csv'], $formats);
    }

    public function testSummaryGivesStringSumsAndHashOfCanonicalDump(): void
    {
        $importer = LedgerTestTools::importer($this->db(), new MockClock('2026-11-05T09:00:00Z'));
        $result = $importer->import(self::HEADER . "2;2026-10-12;2026-09-30;FICTIONAL/2026/09/2;;DE;DE999999999;10.25;12;zażółć\n1;2026-10-12;2026-09-30;FICTIONAL/2026/09/1;;;;4.50;12;\n", 2026);
        $dump = '{"K_1":"1","K_2":"2026-10-12","K_3":"2026-09-30","K_4":"FICTIONAL/2026/09/1","K_5":null,"K_6":null,"K_7":null,"K_8":"4.50","K_9":"12.00","K_10":null}' . "\n"
            . '{"K_1":"2","K_2":"2026-10-12","K_3":"2026-09-30","K_4":"FICTIONAL/2026/09/2","K_5":null,"K_6":"DE","K_7":"DE999999999","K_8":"10.25","K_9":"12.00","K_10":"zażółć"}' . "\n";
        self::assertSame(hash('sha256', $dump), $result->ledgerSha256);
        self::assertSame(['2026-09' => ['12.00' => ['rows' => 2,'sum' => '14.75']]], $result->sums);
        self::assertSame([2, hash('sha256', $dump), $result->sums], $importer->summary('test', 2026));
    }

    public function testImportWaitsForYearLock(): void
    {
        LedgerTestTools::assertWaitsForYearLock($this->db(), 2026, fn() => LedgerTestTools::importer($this->db(), new MockClock('2026-11-05T09:00:00Z'))->import(LedgerTestTools::fixture('ledger-2026-jan-oct.csv'), 2026));
    }

    /** @return list<string> */
    private static function refused(callable $import): array
    {
        try {
            $import();
        } catch (LedgerImportRefused $refused) {
            return $refused->problems;
        }
        self::fail('Import przyjęty.');
    }
}
