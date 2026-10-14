<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Security\Vault;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\FreshBooksTestTools;
use App\Tests\Support\LedgerTestTools;
use Symfony\Component\Clock\MockClock;

/**
 * Strumień `invoices` na ręcznie pisanych fixtures (D-35): backfill dwóch stron, klasyfikacja do go-live F3, kursor,
 * deduplikacja z Subject1 i szkic przed Subject1. Klienci, numery VAT, kwoty i kursy są fikcyjne.
 */
final class FreshBooksInvoicesTest extends DatabaseTestCase
{
    private const RATES = ['EUR' => ['2026-03-09' => '4.2500']];

    public function testFreshBooksBackfillReadsAllPagesAndClassifiesBeforeTransition(): void
    {
        $clock = new MockClock('2026-11-10T09:00:00Z');
        FreshBooksTestTools::settings($this->db(), $clock);
        FreshBooksTestTools::connect($this->db(), $clock, $this->vault());
        $calls = [];
        $sync = FreshBooksTestTools::sync($this->db(), $clock, FreshBooksTestTools::client(FreshBooksTestTools::backfillRoutes(), $calls), $this->vault(), self::RATES);
        // Bez faktury z KSeF: 1003 i 1004 są od dnia przejścia; szkic 1004 i faktura 1003 bez odpowiednika czekają.
        self::assertSame(['draft_created' => 2,'skipped_draft' => 1,'written' => 1], $sync->pollInvoices());
        self::assertSame(['GET ' . FreshBooksTestTools::ACCOUNT_PATH . 'invoices/invoices#1', 'GET ' . FreshBooksTestTools::ACCOUNT_PATH . 'invoices/invoices#2'], $calls);
        $rows = LedgerTestTools::rows($this->db());
        self::assertCount(1, $rows);
        self::assertSame(['2026-11-10', '2026-03-10', 'FB-2026-0101', null, 'DE', '999999999', '4250.00', '12.00', 'freshbooks', 'regular'], [$rows[0]['k_2'], $rows[0]['k_3'], $rows[0]['k_4'], $rows[0]['k_5'], $rows[0]['k_6'], $rows[0]['k_7'], $rows[0]['k_8'], $rows[0]['k_9'], $rows[0]['source'], $rows[0]['kind']]);
        $cursor = $this->db()->fetchAssociative("SELECT backfill_from, backfill_done_at, last_value FROM sync_cursor WHERE connector='freshbooks' AND stream='invoices'");
        self::assertIsArray($cursor);
        // Kursor nie wyprzedza najwcześniejszej zmiany faktury oczekującej (1003 z 2.11.2026).
        self::assertSame(['2026-01-01', '2026-11-02'], [$cursor['backfill_from'], $cursor['last_value']]);
        self::assertNotNull($cursor['backfill_done_at']);
        // Drugi przebieg czyta od `last_value` minus 1 dzień i nic nie dubluje.
        $calls = [];
        $sync = FreshBooksTestTools::sync($this->db(), $clock, FreshBooksTestTools::client(FreshBooksTestTools::backfillRoutes(), $calls), $this->vault(), self::RATES);
        self::assertSame(['draft_unchanged' => 2,'duplicate' => 1,'skipped_draft' => 1], $sync->pollInvoices());
        self::assertCount(1, LedgerTestTools::rows($this->db()));
        self::assertSame(2, F1TestTools::count($this->db(), "SELECT count(*) FROM invoice WHERE status='draft' AND freshbooks_id IS NOT NULL"));
        $last = $this->db()->fetchOne("SELECT count(*) FROM connector_call WHERE connector='freshbooks' AND operation='invoices'");
        self::assertSame(4, $last);
    }

    public function testFreshBooksKsefDedupLinksSubject1InvoiceWithoutSecondRow(): void
    {
        $clock = new MockClock('2026-11-10T09:00:00Z');
        FreshBooksTestTools::settings($this->db(), $clock);
        FreshBooksTestTools::connect($this->db(), $clock, $this->vault());
        $ksef = F1TestTools::subject1($this->db(), $clock)->import($this->subject1Xml('FV/1/11/2026'), 'FIKCYJNY-KSEF-0401', new \DateTimeImmutable('2026-11-02T10:05:00Z'));
        self::assertCount(1, LedgerTestTools::rows($this->db()));
        $calls = [];
        $outcomes = FreshBooksTestTools::sync($this->db(), $clock, FreshBooksTestTools::client(FreshBooksTestTools::backfillRoutes(), $calls), $this->vault(), self::RATES)->pollInvoices();
        self::assertSame(1, $outcomes['linked']);
        $invoices = $this->db()->fetchAllAssociative("SELECT id, invoice_number, ksef_number, status, freshbooks_id, freshbooks_status FROM invoice WHERE invoice_number='FV/1/11/2026'");
        self::assertSame([['id' => $ksef,'invoice_number' => 'FV/1/11/2026','ksef_number' => 'FIKCYJNY-KSEF-0401','status' => 'accepted','freshbooks_id' => '1003','freshbooks_status' => 'sent']], $invoices);
        $rows = $this->db()->fetchAllAssociative("SELECT id, document_number, ksef_number, source, kind FROM revenue_entry WHERE document_number='FV/1/11/2026'");
        self::assertCount(1, $rows);
        self::assertSame('ksef_subject1', $rows[0]['source']);
        EvidenceArtefacts::write('db/freshbooks-ksef-dedup-revenue_entry.csv', EvidenceArtefacts::csv($rows));
        EvidenceArtefacts::write('db/freshbooks-ksef-dedup-invoice.csv', EvidenceArtefacts::csv($invoices));
    }

    public function testFreshBooksDraftBeforeSubject1HandsOverFreshBooksIdInOneTransaction(): void
    {
        $clock = new MockClock('2026-11-10T09:00:00Z');
        FreshBooksTestTools::settings($this->db(), $clock);
        FreshBooksTestTools::connect($this->db(), $clock, $this->vault());
        $calls = [];
        FreshBooksTestTools::sync($this->db(), $clock, FreshBooksTestTools::client(FreshBooksTestTools::backfillRoutes(), $calls), $this->vault(), self::RATES)->pollInvoices();
        $draft = $this->db()->fetchAssociative("SELECT id, status, ready_at, freshbooks_id FROM invoice WHERE invoice_number='FB-2026-0110'");
        self::assertIsArray($draft);
        // `freshbooks.sales_enabled` wyłączony do F3: szkic bez znacznika i bez `ready`.
        self::assertSame(['draft', null, '1004'], [$draft['status'], $draft['ready_at'], $draft['freshbooks_id']]);
        $ksef = F1TestTools::subject1($this->db(), $clock, ['EUR' => ['2026-10-30' => '4.2400']])->import($this->subject1Xml('FB-2026-0110', 'EUR', '500.00'), 'FIKCYJNY-KSEF-0402', new \DateTimeImmutable('2026-11-03T10:05:00Z'));
        $invoices = $this->db()->fetchAllAssociative("SELECT id, status, ksef_number, freshbooks_id FROM invoice WHERE invoice_number='FB-2026-0110' ORDER BY created_at, id");
        self::assertSame([
            ['id' => $draft['id'],'status' => 'cancelled_before_send','ksef_number' => null,'freshbooks_id' => null],
            ['id' => $ksef,'status' => 'accepted','ksef_number' => 'FIKCYJNY-KSEF-0402','freshbooks_id' => '1004'],
        ], $invoices);
        $audit = $this->db()->fetchOne("SELECT details->>'freshbooks_draft_id' FROM audit_log WHERE action='sale.imported_subject1' AND target_id=?", [$ksef]);
        self::assertSame($draft['id'], $audit);
        // Kolejny przebieg widzi fakturę z KSeF pod tym `freshbooks_id`; szkic nie wraca, a numer nie ma luki.
        $calls = [];
        $outcomes = FreshBooksTestTools::sync($this->db(), $clock, FreshBooksTestTools::client(FreshBooksTestTools::backfillRoutes(), $calls), $this->vault(), self::RATES)->pollInvoices();
        self::assertSame(1, $outcomes['unchanged']);
        self::assertSame(1, F1TestTools::count($this->db(), "SELECT count(*) FROM invoice WHERE invoice_number='FB-2026-0110' AND status NOT IN ('cancelled_before_send','rejected')"));
        EvidenceArtefacts::write('db/freshbooks-draft-before-subject1-invoice.csv', EvidenceArtefacts::csv($invoices));
    }

    public function testFreshBooksReconstructWritesBeforeTransitionOnceWithAutoEntriesOff(): void
    {
        $clock = new MockClock('2026-11-10T09:00:00Z');
        FreshBooksTestTools::settings($this->db(), $clock, null);
        FreshBooksTestTools::connect($this->db(), $clock, $this->vault());
        $calls = [];
        $routes = FreshBooksTestTools::backfillRoutes();
        $sync = FreshBooksTestTools::sync($this->db(), $clock, FreshBooksTestTools::client($routes, $calls), $this->vault(), self::RATES);
        // Polling bez przełączenia nie pisze wierszy; odtworzenie pisze, a drugie odtworzenie nic nie dubluje.
        self::assertSame(1, $sync->pollInvoices()['fields_only']);
        self::assertCount(0, LedgerTestTools::rows($this->db()));
        self::assertSame(1, $sync->reconstruct(2026)['written']);
        self::assertSame(1, $sync->reconstruct(2026)['duplicate']);
        self::assertCount(1, LedgerTestTools::rows($this->db()));
        self::assertContains('GET ' . FreshBooksTestTools::ACCOUNT_PATH . 'invoices/invoices#2', $calls);
    }

    public function testFreshBooksRevenueDateFollowsEarlierPaymentArticle14(): void
    {
        $clock = new MockClock('2026-11-10T09:00:00Z');
        FreshBooksTestTools::settings($this->db(), $clock);
        FreshBooksTestTools::connect($this->db(), $clock, $this->vault());
        // Przedpłata 5.03.2026 za fakturę 1001 z 10.03.2026: przychód z dniem zapłaty, kurs z 4.03.
        $now = $clock->now()->format('c');
        $this->db()->insert('payment', ['id' => (string) \Symfony\Component\Uid\UuidV7::generate($clock->now()),'environment' => 'test','freshbooks_payment_id' => '500','freshbooks_invoice_id' => '1001',
            'paid_on' => '2026-03-05','amount' => '1000.00','currency' => 'EUR','created_at' => $now,'updated_at' => $now]);
        $calls = [];
        FreshBooksTestTools::sync($this->db(), $clock, FreshBooksTestTools::client(FreshBooksTestTools::backfillRoutes(), $calls), $this->vault(), ['EUR' => ['2026-03-04' => '4.2000']])->pollInvoices();
        $rows = LedgerTestTools::rows($this->db());
        self::assertSame(['2026-03-05', 'FB-2026-0101', '4200.00'], [$rows[0]['k_3'], $rows[0]['k_4'], $rows[0]['k_8']]);
    }

    private function subject1Xml(string $number, string $currency = 'PLN', string $net = '1000.00'): string
    {
        return F1TestTools::fa3(['number' => $number,'issue' => '2026-11-02','service' => '2026-11-02','currency' => $currency,'seller' => F1TestTools::plParty(F1TestTools::OWN_NIP, 'Fikcyjna JDG'),
            'buyer' => $currency === 'PLN' ? F1TestTools::plParty(F1TestTools::CLIENT_NIP, 'Fikcyjny klient sp. z o.o.') : F1TestTools::euParty('DE', '999999999', 'Fikcyjny klient GmbH'),
            'lines' => [['net' => $net,'code' => $currency === 'PLN' ? '23' : 'np II','vat' => $currency === 'PLN' ? '230.00' : '0.00']]]);
    }

    private function vault(): Vault
    {
        $vault = self::getContainer()->get(Vault::class);
        self::assertInstanceOf(Vault::class, $vault);
        return $vault;
    }
}
