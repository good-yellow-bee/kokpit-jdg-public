<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\LedgerTestTools;
use Symfony\Component\Clock\MockClock;

final class LedgerImportLateK2Test extends DatabaseTestCase
{
    public function testMissingEntryDateBecomesImportDayWithoutBackdating(): void
    {
        $csv = "K_1;K_2;K_3;K_4;K_5;K_6;K_7;K_8;K_9;K_10\n1;;2026-01-31;FICTIONAL/2026/01/1;;DE;DE999999999;4100.00;12;odtworzony, dane fictional\n";
        // 19.11.2026 23:30 UTC to już 20.11 w Europe/Warsaw; K_2 to dzień w Warszawie.
        LedgerTestTools::importer($this->connection, new MockClock('2026-11-19T23:30:00Z'))->import($csv, 2026);
        $rows = LedgerTestTools::rows($this->connection);
        self::assertCount(1, $rows);
        self::assertSame(['2026-11-20','2026-01-31',2026], [$rows[0]['k_2'], $rows[0]['k_3'], $rows[0]['year']]);
        EvidenceArtefacts::write('db/ledger-import-late-k2-revenue_entry.csv', EvidenceArtefacts::csv($rows));
    }
}
