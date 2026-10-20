<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\FreshBooks\FreshBooksInvoice;
use App\Invoice\Fa3Party;
use App\Ledger\LedgerReconciliation;
use App\Ledger\RevenueDocument;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\FreshBooksTestTools;
use App\Tests\Support\LedgerTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/** Poszczególne kontrole raportu uzgodnień: import wobec wpisu audytu, Subject1 i FreshBooks. Dane fikcyjne. */
final class LedgerReconciliationChecksTest extends DatabaseTestCase
{
    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-12-10T09:00:00Z');
    }

    public function testImportCheckComparesAuditCountsAndTotal(): void
    {
        $audit = F1TestTools::audit($this->db(), $this->clock);
        $audit->append('ledger.imported', ['year' => 2026,'total_in_file' => 12.5], 'test');
        self::assertSame(['difference', 'plik -1 wierszy, K_8 ?; ewidencja 0 wierszy, K_8 0'], $this->import());
        LedgerTestTools::importer($this->db(), $this->clock)->import(LedgerTestTools::fixture('ledger-2026-jan-oct.csv'), 2026);
        $audit->append('ledger.imported', ['year' => 2026,'rows_in_file' => 14,'total_in_file' => '37731.51'], 'test');
        self::assertSame(['difference', 'plik 14 wierszy, K_8 37731.51; ewidencja 14 wierszy, K_8 37731.50'], $this->import());
        $audit->append('ledger.imported', ['year' => 2026,'rows_in_file' => 15,'total_in_file' => '37731.50'], 'test');
        self::assertSame('difference', $this->import()[0]);
        $audit->append('ledger.imported', ['year' => 2026,'rows_in_file' => 14], 'test');
        self::assertSame(['ok', 'plik 14 wierszy, K_8 ?; ewidencja 14 wierszy, K_8 37731.50'], $this->import());
    }

    public function testSubject1RowsOnOtherDateAreDateDifference(): void
    {
        $writer = F1TestTools::writer($this->db(), $this->clock);
        $late = $this->ksefInvoice('FV/7/11/2026', 'FIKCYJNY-KSEF-0801', '2026-11-05');
        $onTime = $this->ksefInvoice('FV/8/11/2026', 'FIKCYJNY-KSEF-0802', '2026-11-06');
        $writer->write(new RevenueDocument('ksef_subject1', $late, 'FV/7/11/2026', 'FIKCYJNY-KSEF-0801', 'PL', F1TestTools::CLIENT_NIP, '2026-11-04', 'PLN', null, ['12.00' => '100.00']));
        $writer->write(new RevenueDocument('ksef_subject1', $onTime, 'FV/8/11/2026', 'FIKCYJNY-KSEF-0802', 'PL', F1TestTools::CLIENT_NIP, '2026-11-06', 'PLN', null, ['12.00' => '100.00']));
        // Ten sam numer KSeF także w wierszu z innego źródła (zapis spoza dokumentu omija deduplikację).
        $twice = $this->ksefInvoice('FV/9/11/2026', 'FIKCYJNY-KSEF-0803', '2026-11-07');
        $writer->write(new RevenueDocument('ksef_subject1', $twice, 'FV/9/11/2026', 'FIKCYJNY-KSEF-0803', 'PL', F1TestTools::CLIENT_NIP, '2026-11-07', 'PLN', null, ['12.00' => '100.00']));
        $writer->append(2026, [['revenue_date' => '2026-11-07','document_number' => 'FB-FV/9/11/2026','ksef_number' => 'FIKCYJNY-KSEF-0803','amount_pln' => '100.00','rate_pct' => '12.00','source' => 'freshbooks','kind' => 'regular']]);
        $rows = array_values(array_filter($this->report(null), static fn(array $row): bool => $row['check'] === 'ksef_subject1'));
        self::assertSame([['FV/7/11/2026', 'date_difference', 'K_3 2026-11-04, faktura 2026-11-05'], ['FV/8/11/2026', 'ok', ''], ['FV/9/11/2026', 'duplicate', '']], array_map(static fn(array $row): array => [$row['document_number'], $row['status'], $row['detail']], $rows));
    }

    public function testFreshBooksCheckSkipsDraftsAndReportsEveryInvoice(): void
    {
        FreshBooksTestTools::settings($this->db(), $this->clock);
        $ledger = FreshBooksTestTools::ledger($this->db(), $this->clock);
        LedgerTestTools::importer($this->db(), $this->clock)->import("K_1;K_2;K_3;K_4;K_5;K_6;K_7;K_8;K_9;K_10\n1;2026-10-12;2026-10-02;FB-2026-0903;;PL;7777777777;300.00;12;\n", 2026);
        // Dwie stawki z jednego źródła: jedna data, jedno źródło.
        $twoRates = self::invoice('903', 'FB-2026-0901', '2026-10-01', lines: [['name' => 'Development','description' => null,'net' => '100.00','vat' => '0.00'], ['name' => 'Consulting','description' => null,'net' => '200.00','vat' => '0.00']]);
        $prepaid = self::invoice('904', 'FB-2026-0904', '2026-10-10');
        $paidLater = self::invoice('905', 'FB-2026-0905', '2026-10-11');
        $this->payment('904', '2026-10-08');
        $this->payment('905', '2026-10-15');
        $duplicate = self::invoice('906', 'FB-2026-0903', '2026-10-02', net: '300.00');
        $paidAfterEntry = self::invoice('909', 'FB-2026-0909', '2026-10-12');
        foreach ([$twoRates, $prepaid, $paidLater, $duplicate, $paidAfterEntry] as $invoice) {
            self::assertSame('written', $ledger->ledger($invoice, true));
        }
        // Przedpłata zarejestrowana po wpisie: data przychodu z FreshBooks różni się od K_3.
        $this->payment('909', '2026-10-09');
        $report = $this->report([
            self::invoice('907', 'FB-2026-0907', '2026-10-03', 'draft'),
            $twoRates,
            self::invoice('908', 'FB-2026-0908', '2026-10-04'),
            $prepaid,
            $paidLater,
            $duplicate,
            $paidAfterEntry,
        ]);
        $rows = array_values(array_filter($report, static fn(array $row): bool => $row['check'] === 'freshbooks'));
        self::assertSame([
            ['FB-2026-0901', 'freshbooks', 'ok'],
            ['FB-2026-0908', '', 'missing'],
            ['FB-2026-0904', 'freshbooks', 'ok'],
            ['FB-2026-0905', 'freshbooks', 'ok'],
            ['FB-2026-0903', 'freshbooks,import', 'duplicate'],
            ['FB-2026-0909', 'freshbooks', 'date_difference'],
        ], array_map(static fn(array $row): array => [$row['document_number'], $row['sources'], $row['status']], $rows));
        self::assertSame('FreshBooks 2026-10-09, K_3 2026-10-12', $rows[5]['detail']);
    }

    public function testFreshBooksRowOfLinkedInvoiceCarriesKsefNumber(): void
    {
        FreshBooksTestTools::settings($this->db(), $this->clock);
        F1TestTools::subject1($this->db(), $this->clock)->import(F1TestTools::fa3(['number' => 'FV/1/11/2026','issue' => '2026-11-05','service' => '2026-11-05','seller' => F1TestTools::plParty(F1TestTools::OWN_NIP, 'Fikcyjna JDG'),'buyer' => F1TestTools::plParty(F1TestTools::CLIENT_NIP, 'Fikcyjny klient sp. z o.o.')]), 'FIKCYJNY-KSEF-0501', new \DateTimeImmutable('2026-11-05T10:05:00Z'));
        $invoice = self::invoice('2003', 'FV/1/11/2026', '2026-11-05', net: '1000.00');
        self::assertSame('linked', FreshBooksTestTools::ledger($this->db(), $this->clock)->classify($invoice, true, '2026-11-01'));
        $rows = array_values(array_filter($this->report([$invoice]), static fn(array $row): bool => $row['check'] === 'freshbooks'));
        self::assertSame([['FV/1/11/2026', 'FIKCYJNY-KSEF-0501', 'ksef_subject1', 'ok']], array_map(static fn(array $row): array => [$row['document_number'], $row['ksef_number'], $row['sources'], $row['status']], $rows));
    }

    /** @return array{string,string} */
    private function import(): array
    {
        $row = $this->report(null)[0];
        self::assertSame('import', $row['check']);
        return [$row['status'], $row['detail']];
    }

    /**
     * @param list<FreshBooksInvoice>|null $freshbooks
     * @return list<array{check:string,document_number:string,ksef_number:string,sources:string,status:string,detail:string}>
     */
    private function report(?array $freshbooks): array
    {
        return (new LedgerReconciliation($this->db(), F1TestTools::fxRates($this->db(), $this->clock), ConnectorTestTools::configuration(false)))->report(2026, $freshbooks);
    }

    private function ksefInvoice(string $number, string $ksef, string $revenueDate): string
    {
        $counterparty = F1TestTools::directory($this->db(), $this->clock)->freshbooksClient('301', new Fa3Party(F1TestTools::CLIENT_NIP, null, null, null, null, false, 'Fikcyjny klient sp. z o.o.', ['country' => 'PL','line1' => null,'line2' => null]));
        $id = (string) UuidV7::generate($this->clock->now());
        $now = $this->clock->now()->format('c');
        $this->db()->insert('invoice', ['id' => $id,'environment' => 'test','direction' => 'sale','kind' => 'VAT','invoice_number' => $number,'ksef_number' => $ksef,'counterparty_id' => $counterparty,'currency' => 'PLN','issue_date' => $revenueDate,'revenue_date' => $revenueDate,'net_total' => '100.00','vat_total' => '23.00','gross_total' => '123.00','status' => 'accepted','created_at' => $now,'updated_at' => $now]);
        return $id;
    }

    private function payment(string $invoiceId, string $paidOn): void
    {
        $now = $this->clock->now()->format('c');
        $this->db()->insert('payment', ['id' => (string) UuidV7::generate($this->clock->now()),'environment' => 'test','freshbooks_payment_id' => '7' . $invoiceId,'freshbooks_invoice_id' => $invoiceId,'paid_on' => $paidOn,'amount' => '100.00','currency' => 'PLN','created_at' => $now,'updated_at' => $now]);
    }

    /** @param ?list<array{name:string,description:?string,net:string,vat:string}> $lines */
    private static function invoice(string $id, string $number, string $created, string $status = 'sent', string $net = '100.00', ?array $lines = null): FreshBooksInvoice
    {
        $lines ??= [['name' => 'Development','description' => null,'net' => $net,'vat' => '0.00']];
        $total = array_reduce($lines, static fn(string $sum, array $line): string => (string) \Brick\Math\BigDecimal::of($sum)->plus($line['net']), '0.00');
        return new FreshBooksInvoice($id, $number, '301', 'Fikcyjny klient', ['line1' => null,'line2' => null,'country' => 'Poland'], null, 'PLN', $total, $total, '0.00', $created, $status, false, $created, $lines);
    }
}
