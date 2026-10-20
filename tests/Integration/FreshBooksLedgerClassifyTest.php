<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\FreshBooks\FreshBooksInvoice;
use App\Ledger\FreshBooksLedger;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\FreshBooksTestTools;
use App\Tests\Support\LedgerTestTools;
use Symfony\Component\Clock\MockClock;

/** Klasyfikacja faktur FreshBooks wobec KSeF, dnia przejścia i znanych wierszy `invoice` (D-45). Dane fikcyjne. */
final class FreshBooksLedgerClassifyTest extends DatabaseTestCase
{
    private const TRANSITION = '2026-11-01';
    private MockClock $clock;
    private FreshBooksLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-11-10T09:00:00Z');
        FreshBooksTestTools::settings($this->db(), $this->clock);
        $this->ledger = FreshBooksTestTools::ledger($this->db(), $this->clock);
    }

    public function testLinkTouchesOnlyTheKsefInvoiceWithThatNumber(): void
    {
        $this->subject1('FV/1/11/2026', 'FIKCYJNY-KSEF-0701');
        $this->subject1('FV/2/11/2026', 'FIKCYJNY-KSEF-0702');
        self::assertSame('linked', $this->classify(self::invoice('1003', 'FV/1/11/2026', '2026-11-02')));
        self::assertSame([['FV/1/11/2026', '1003', 'sent'], ['FV/2/11/2026', null, null]], $this->invoices());
    }

    public function testTransitionDayItselfGivesDraft(): void
    {
        self::assertSame('draft_created', $this->classify(self::invoice('1101', 'FB-2026-1101', self::TRANSITION)));
        self::assertSame('written', $this->classify(self::invoice('1102', 'FB-2026-1102', '2026-10-31')));
    }

    public function testLedgerSumsLinesPerRateAndMatchesByDescription(): void
    {
        $lines = [
            ['name' => 'Consulting','description' => null,'net' => '0.00','vat' => '0.00'],
            ['name' => 'Development','description' => null,'net' => '100.25','vat' => '0.00'],
            ['name' => 'Inna pozycja','description' => 'Fikcyjna usługa 1, wdrożenie','net' => '10.00','vat' => '0.00'],
            ['name' => 'Development','description' => 'druga część','net' => '50.10','vat' => '0.00'],
        ];
        self::assertSame('written', $this->ledger->ledger(self::invoice('1201', 'FB-2026-1201', '2026-10-20', lines: $lines), true));
        $rows = LedgerTestTools::rows($this->db());
        self::assertSame([['FB-2026-1201', '160.35', '12.00']], array_map(static fn(array $row): array => [$row['k_4'], $row['k_8'], $row['k_9']], $rows));
    }

    public function testDraftStatusChangeIsStoredWithoutNewVersion(): void
    {
        self::assertSame('draft_created', $this->classify(self::invoice('1301', 'FB-2026-1301', '2026-11-03')));
        self::assertSame('draft_created', $this->classify(self::invoice('1302', 'FB-2026-1302', '2026-11-03')));
        self::assertSame('draft_unchanged', $this->classify(self::invoice('1301', 'FB-2026-1301', '2026-11-03', 'viewed')));
        self::assertSame(['viewed', 1], $this->state('1301', 'freshbooks_status, version'));
        self::assertSame(['sent', 1], $this->state('1302', 'freshbooks_status, version'));
    }

    public function testDraftMissingPartyOrClientWaits(): void
    {
        self::assertSame('pending', $this->classify(self::invoice('1401', 'FB-2026-1401', '2026-11-03', country: null)));
        self::assertSame('pending', $this->classify(self::invoice('1402', 'FB-2026-1402', '2026-11-03', clientId: null)));
        self::assertSame(0, F1TestTools::count($this->db(), "SELECT count(*) FROM invoice WHERE freshbooks_id IN ('1401','1402')"));
    }

    public function testCancelledDraftKeepsDeletedFreshBooksStatus(): void
    {
        $this->classify(self::invoice('1501', 'FB-2026-1501', '2026-11-03'));
        $this->classify(self::invoice('1502', 'FB-2026-1502', '2026-11-03'));
        self::assertSame('draft_cancelled', $this->classify(self::invoice('1501', 'FB-2026-1501', '2026-11-03', deleted: true)));
        self::assertSame('unchanged', $this->classify(self::invoice('1501', 'FB-2026-1501', '2026-11-03', 'sent')));
        self::assertSame(['cancelled_before_send', 'deleted'], $this->state('1501', 'status, freshbooks_status'));
        self::assertSame(['draft', 'sent'], $this->state('1502', 'status, freshbooks_status'));
    }

    public function testAcceptedInvoiceDeletedInFreshBooksStaysAccepted(): void
    {
        $this->subject1('FV/1/11/2026', 'FIKCYJNY-KSEF-0701');
        $this->classify(self::invoice('1003', 'FV/1/11/2026', '2026-11-02'));
        self::assertSame('unchanged', $this->classify(self::invoice('1003', 'FV/1/11/2026', '2026-11-02', deleted: true)));
        self::assertSame(['accepted', 'deleted', null], $this->state('1003', 'status, freshbooks_status, source_hash'));
    }

    public function testAcceptedInvoiceFirstReadSetsHashThenTracksStatusAndEdits(): void
    {
        $this->subject1('FV/1/11/2026', 'FIKCYJNY-KSEF-0701');
        $this->subject1('FV/2/11/2026', 'FIKCYJNY-KSEF-0702');
        $first = self::invoice('1003', 'FV/1/11/2026', '2026-11-02');
        $this->classify($first);
        $this->classify(self::invoice('1004', 'FV/2/11/2026', '2026-11-02'));
        $other = ['sent', null];

        self::assertSame('unchanged', $this->classify($first));
        self::assertSame(['sent', $first->sourceHash()], $this->state('1003', 'freshbooks_status, source_hash'));
        self::assertSame($other, $this->state('1004', 'freshbooks_status, source_hash'));

        self::assertSame('unchanged', $this->classify(self::invoice('1003', 'FV/1/11/2026', '2026-11-02', 'viewed')));
        self::assertSame(['viewed', $first->sourceHash()], $this->state('1003', 'freshbooks_status, source_hash'));
        self::assertSame($other, $this->state('1004', 'freshbooks_status, source_hash'));

        self::assertSame('edited_after_acceptance', $this->classify(self::invoice('1003', 'FV/1/11/2026', '2026-11-02', 'paid', net: '1100.00')));
        self::assertSame(['paid', $first->sourceHash()], $this->state('1003', 'freshbooks_status, source_hash'));
        self::assertSame($other, $this->state('1004', 'freshbooks_status, source_hash'));
        self::assertSame('edited_after_acceptance', $this->classify(self::invoice('1003', 'FV/1A/11/2026', '2026-11-02', 'paid', net: '1100.00')));
        self::assertSame('number_changed', $this->classify(self::invoice('1003', 'FV/1A/11/2026', '2026-11-02', 'paid')));
    }

    private function classify(FreshBooksInvoice $invoice): string
    {
        return $this->ledger->classify($invoice, true, self::TRANSITION);
    }

    /** @return list<mixed> */
    private function state(string $freshbooksId, string $columns): array
    {
        $row = $this->db()->fetchNumeric(sprintf('SELECT %s FROM invoice WHERE freshbooks_id=?', $columns), [$freshbooksId]);
        self::assertIsArray($row);
        return $row;
    }

    /** @return list<list<mixed>> */
    private function invoices(): array
    {
        return $this->db()->fetchAllNumeric("SELECT invoice_number, freshbooks_id, freshbooks_status FROM invoice WHERE direction='sale' ORDER BY invoice_number");
    }

    private function subject1(string $number, string $ksef): void
    {
        F1TestTools::subject1($this->db(), $this->clock)->import(F1TestTools::fa3(['number' => $number,'issue' => '2026-11-02','service' => '2026-11-02','seller' => F1TestTools::plParty(F1TestTools::OWN_NIP, 'Fikcyjna JDG'),'buyer' => F1TestTools::plParty(F1TestTools::CLIENT_NIP, 'Fikcyjny klient sp. z o.o.')]), $ksef, new \DateTimeImmutable('2026-11-02T10:05:00Z'));
    }

    /** @param ?list<array{name:string,description:?string,net:string,vat:string}> $lines */
    private static function invoice(string $id, string $number, string $created, string $status = 'sent', bool $deleted = false, ?string $country = 'Poland', ?string $clientId = '301', string $net = '1000.00', ?array $lines = null): FreshBooksInvoice
    {
        return new FreshBooksInvoice($id, $number, $clientId, 'Fikcyjny klient sp. z o.o.', ['line1' => 'ul. Fikcyjna 1','line2' => null,'country' => $country], null, 'PLN', $net, $net, '0.00', $created, $status, $deleted, $created, $lines ?? [['name' => 'Development','description' => 'Fikcyjna usługa','net' => $net,'vat' => '0.00']]);
    }
}
