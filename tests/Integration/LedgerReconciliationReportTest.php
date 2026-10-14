<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\FreshBooks\FreshBooksInvoice;
use App\Ledger\LedgerReconciliation;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\FreshBooksTestTools;
use App\Tests\Support\LedgerTestTools;
use Symfony\Component\Clock\MockClock;

/**
 * Raport uzgodnień (kryterium `ledger-reconciliation-report`) na fikcyjnym zbiorze: import, KSeF Subject1, FreshBooks
 * i duplikaty między źródłami według „Uzgodnień” w docs/modules/ewidencja.md.
 */
final class LedgerReconciliationReportTest extends DatabaseTestCase
{
    private const RATES = ['EUR' => ['2026-10-19' => '4.2500']];

    public function testLedgerReconciliationReportCoversAllChecksAndExportsCsv(): void
    {
        $clock = new MockClock('2026-11-03T09:00:00Z');
        // Import pliku (14 wierszy) przed innymi źródłami.
        LedgerTestTools::importer($this->db(), $clock)->import(LedgerTestTools::fixture('ledger-2026-jan-oct.csv'), 2026);
        $clock->modify('2026-12-10T09:00:00Z');
        FreshBooksTestTools::settings($this->db(), $clock);
        $party = ['seller' => F1TestTools::plParty(F1TestTools::OWN_NIP, 'Fikcyjna JDG'),'buyer' => F1TestTools::plParty(F1TestTools::CLIENT_NIP, 'Fikcyjny klient sp. z o.o.')];
        // Subject1: jedna faktura z wierszem `regular`, druga bez stawki w mapie (bez wiersza).
        F1TestTools::subject1($this->db(), $clock)->import(F1TestTools::fa3(['number' => 'FV/1/11/2026','issue' => '2026-11-05','service' => '2026-11-05','lines' => [['net' => '1000.00','code' => '23','vat' => '230.00']]] + $party), 'FIKCYJNY-KSEF-0501', new \DateTimeImmutable('2026-11-05T10:05:00Z'));
        F1TestTools::subject1($this->db(), $clock)->import(F1TestTools::fa3(['number' => 'FV/2/11/2026','issue' => '2026-11-06','service' => '2026-11-06','lines' => [['net' => '100.00','code' => '23','vat' => '23.00'], ['net' => '50.00','code' => '23','vat' => '11.50']]] + $party), 'FIKCYJNY-KSEF-0502', new \DateTimeImmutable('2026-11-06T10:05:00Z'));
        // FreshBooks: faktura EUR w ewidencji i ta sama faktura z numerem z importu (bez numeru VAT, więc drugi zestaw wierszy).
        $ledger = FreshBooksTestTools::ledger($this->db(), $clock, self::RATES);
        $eur = self::invoice('2001', 'FB-2026-0201', 'EUR', '100.00', '2026-10-20', 'paid');
        $copy = self::invoice('2002', 'FICTIONAL/2026/10/3', 'PLN', '500.00', '2026-10-31', 'sent');
        self::assertSame('written', $ledger->ledger($eur, true));
        self::assertSame('written', $ledger->ledger($copy, true));

        $freshbooks = [
            // Kwota zmieniona we FreshBooks po wpisie: różnica do wyjaśnienia.
            self::invoice('2001', 'FB-2026-0201', 'EUR', '110.00', '2026-10-20', 'paid'),
            $copy,
            self::invoice('2003', 'FV/1/11/2026', 'PLN', '1000.00', '2026-11-05', 'sent'),
            self::invoice('2004', 'FB-2026-0204', 'PLN', '300.00', '2026-11-07', 'sent'),
            self::invoice('2005', 'FB-2026-0205', 'PLN', '999.00', '2026-11-08', 'draft'),
        ];
        $report = (new LedgerReconciliation($this->db(), F1TestTools::fxRates($this->db(), $clock, self::RATES), ConnectorTestTools::configuration(false)))->report(2026, $freshbooks);
        self::assertSame([
            ['import', '', 'ok'],
            ['ksef_subject1', 'FV/1/11/2026', 'ok'],
            ['ksef_subject1', 'FV/2/11/2026', 'missing'],
            ['freshbooks', 'FB-2026-0201', 'amount_difference'],
            ['freshbooks', 'FICTIONAL/2026/10/3', 'duplicate'],
            ['freshbooks', 'FV/1/11/2026', 'ok'],
            ['freshbooks', 'FB-2026-0204', 'missing'],
            ['duplicates', 'FICTIONAL/2026/10/3', 'duplicate'],
        ], array_map(static fn(array $row): array => [$row['check'], $row['document_number'], $row['status']], $report));
        self::assertSame('plik 14 wierszy, K_8 37731.50; ewidencja 14 wierszy, K_8 37731.50', $report[0]['detail']);
        self::assertSame('FreshBooks 467.50, ewidencja 425.00', $report[3]['detail']);
        self::assertSame('freshbooks,import', $report[7]['sources']);
        $csv = LedgerReconciliation::csv($report);
        self::assertStringStartsWith("check;document_number;ksef_number;sources;status;detail\nimport;;;import;ok;", $csv);
        EvidenceArtefacts::write('cmd/ledger-reconciliation-report.csv', $csv);
    }

    public function testLedgerReconciliationReportWithoutFreshBooksSaysNotConnected(): void
    {
        $report = (new LedgerReconciliation($this->db(), F1TestTools::fxRates($this->db(), new MockClock('2026-12-10T09:00:00Z')), ConnectorTestTools::configuration(false)))->report(2026, null);
        self::assertSame([['import', 'ok'], ['freshbooks', 'not_connected'], ['duplicates', 'ok']], array_map(static fn(array $row): array => [$row['check'], $row['status']], $report));
    }

    private static function invoice(string $id, string $number, string $currency, string $net, string $created, string $status): FreshBooksInvoice
    {
        return new FreshBooksInvoice(
            $id,
            $number,
            '301',
            'Fikcyjny klient',
            ['line1' => null,'line2' => null,'country' => 'Poland'],
            null,
            $currency,
            $net,
            $net,
            '0.00',
            $created,
            $status,
            false,
            $created . ' 10:00:00',
            [['name' => 'Development','description' => 'Fikcyjna usługa','net' => $net,'vat' => '0.00']],
        );
    }
}
