<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\LedgerTestTools;
use Symfony\Component\Clock\MockClock;

final class LedgerYearBoundaryTest extends DatabaseTestCase
{
    public function testJanuaryEntryForDecemberStaysInPreviousYear(): void
    {
        $csv = "K_1;K_2;K_3;K_4;K_5;K_6;K_7;K_8;K_9;K_10\n"
            . "1;2026-12-05;2026-12-01;FICTIONAL/2026/12/1;;DE;DE999999999;4200.00;12;dane fictional\n"
            . "2;2027-01-15;2026-12-20;FICTIONAL/2026/12/2;;DE;DE999999999;4300.00;12;\n";
        LedgerTestTools::importer($this->connection, new MockClock('2027-01-16T09:00:00Z'))->import($csv, 2026);
        $rows = LedgerTestTools::rows($this->connection);
        self::assertSame([2026, 2, '2027-01-15', '2026-12-20'], [$rows[1]['year'], $rows[1]['lp'], $rows[1]['k_2'], $rows[1]['k_3']]);
        self::assertSame([], LedgerTestTools::rows($this->connection, 2027));
        EvidenceArtefacts::write('db/ledger-year-boundary-revenue_entry.csv', EvidenceArtefacts::csv($rows));
    }
}
