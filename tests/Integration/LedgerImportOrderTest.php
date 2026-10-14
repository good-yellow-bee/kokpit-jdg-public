<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\LedgerImportRefused;
use App\Tests\Support\LedgerTestTools;
use Symfony\Component\Clock\MockClock;
use App\Tests\Support\F1TestTools;
use Symfony\Component\Uid\Uuid;

final class LedgerImportOrderTest extends DatabaseTestCase
{
    public function testImportRefusesYearWithRowsFromAnotherSource(): void
    {
        // Fikcyjny wiersz Subject1 z fakturą z importu F1 (klucz obcy do `invoice`).
        $clock = new MockClock('2026-12-05T09:00:00Z');
        $xml = F1TestTools::fa3(['issue' => '2026-11-30','service' => '2026-11-30','seller' => F1TestTools::plParty(F1TestTools::OWN_NIP, 'Fikcyjna JDG'),'buyer' => F1TestTools::plParty(F1TestTools::CLIENT_NIP, 'Fikcyjny klient')]);
        $invoice = F1TestTools::subject1($this->connection, $clock)->import($xml, 'FIKCYJNY-KSEF-0901', new \DateTimeImmutable('2026-11-30T10:00:00Z'));
        $this->connection->insert('revenue_entry', ['id' => (string) Uuid::v7(),'environment' => 'test','year' => 2026,'lp' => 1,'entry_date' => '2026-12-02','revenue_date' => '2026-11-30','document_number' => 'FICTIONAL/2026/11/9','amount_pln' => '100.00','rate_pct' => '12.00','source' => 'ksef_subject1','kind' => 'regular','invoice_id' => $invoice,'recorded_at' => '2026-12-02T10:00:00Z']);
        try {
            LedgerTestTools::importer($this->connection, $clock)->import(LedgerTestTools::fixture('ledger-2026-jan-oct.csv'), 2026);
            self::fail('Import po innym źródle musi dać odmowę.');
        } catch (LedgerImportRefused $refused) {
            self::assertStringContainsString('ksef_subject1', implode("\n", $refused->problems));
        }
        self::assertSame(1, $this->connection->fetchOne('SELECT count(*) FROM revenue_entry'));
    }
}
