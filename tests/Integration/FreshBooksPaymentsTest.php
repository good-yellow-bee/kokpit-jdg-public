<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Security\Vault;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\FreshBooksTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Strumień `payments` i limit 429 na ręcznie pisanych fixtures (D-35): płatności idempotentne, przypisanie po
 * `freshbooks_id` albo po numerze faktury, `blocked_until` z `Retry-After`. Kwoty i kursy są fikcyjne.
 */
final class FreshBooksPaymentsTest extends DatabaseTestCase
{
    private const RATES = ['EUR' => ['2026-03-09' => '4.2500','2026-03-19' => '4.2600','2026-10-30' => '4.2400']];

    public function testFreshBooksPollPaymentsIsIdempotentAndMatchesInvoices(): void
    {
        $clock = new MockClock('2026-11-25T09:00:00Z');
        FreshBooksTestTools::settings($this->db(), $clock);
        FreshBooksTestTools::connect($this->db(), $clock, $this->vault());
        $ksef = F1TestTools::subject1($this->db(), $clock)->import(F1TestTools::fa3(['number' => 'FV/1/11/2026','issue' => '2026-11-02','service' => '2026-11-02','seller' => F1TestTools::plParty(F1TestTools::OWN_NIP, 'Fikcyjna JDG'),
            'buyer' => F1TestTools::plParty(F1TestTools::CLIENT_NIP, 'Fikcyjny klient sp. z o.o.')]), 'FIKCYJNY-KSEF-0501', new \DateTimeImmutable('2026-11-02T10:05:00Z'));
        $calls = [];
        $sync = FreshBooksTestTools::sync($this->db(), $clock, FreshBooksTestTools::client(FreshBooksTestTools::backfillRoutes(), $calls), $this->vault(), self::RATES);
        $sync->pollInvoices();
        $calls = [];
        // 502 trafia do faktury z KSeF po `freshbooks_id`; 501 po numerze faktury 1001 odczytanym z FreshBooks.
        self::assertSame(['fx_posted' => 1,'matched' => 2,'new' => 2], $sync->pollPayments());
        self::assertSame(['GET ' . FreshBooksTestTools::ACCOUNT_PATH . 'payments/payments#1', 'GET ' . FreshBooksTestTools::ACCOUNT_PATH . 'invoices/invoices/1001'], $calls);
        $calls = [];
        self::assertSame(['known' => 2,'matched' => 0], $sync->pollPayments());
        self::assertSame(['GET ' . FreshBooksTestTools::ACCOUNT_PATH . 'payments/payments#1'], $calls);
        $payments = $this->db()->fetchAllAssociative('SELECT p.freshbooks_payment_id, p.freshbooks_invoice_id, p.paid_on, p.amount, p.currency, p.gateway, p.invoice_id, r.document_number AS revenue_entry_document, p.matched_at IS NOT NULL AS matched, p.fx_posted_at IS NOT NULL AS fx_posted
            FROM payment p LEFT JOIN revenue_entry r ON r.id=p.revenue_entry_id ORDER BY p.freshbooks_payment_id');
        self::assertSame([
            ['freshbooks_payment_id' => '501','freshbooks_invoice_id' => '1001','paid_on' => '2026-03-20','amount' => '1000.00','currency' => 'EUR','gateway' => 'Stripe','invoice_id' => null,'revenue_entry_document' => 'FB-2026-0101','matched' => true,'fx_posted' => true],
            ['freshbooks_payment_id' => '502','freshbooks_invoice_id' => '1003','paid_on' => '2026-11-20','amount' => '1230.00','currency' => 'PLN','gateway' => null,'invoice_id' => $ksef,'revenue_entry_document' => null,'matched' => true,'fx_posted' => false],
        ], $payments);
        // Kurs NBP z 19.03.2026 (ostatni dzień roboczy przed `paid_on`): (4,2600 − 4,2500) × 1 000,00 EUR = +10,00 zł.
        self::assertSame('10.00', $this->db()->fetchOne("SELECT amount_pln FROM revenue_entry WHERE kind='fx_difference'"));
        $cursor = $this->db()->fetchOne("SELECT last_value FROM sync_cursor WHERE connector='freshbooks' AND stream='payments'");
        self::assertSame('2026-11-25', $cursor);
        EvidenceArtefacts::write('db/freshbooks-poll-payments-payment.csv', EvidenceArtefacts::csv($this->db()->fetchAllAssociative('SELECT freshbooks_payment_id, freshbooks_invoice_id, paid_on, amount, currency, invoice_id, revenue_entry_id, matched_at, fx_posted_at FROM payment ORDER BY freshbooks_payment_id')));
    }

    public function testFreshBooksRateLimit429SetsBlockedUntilAndSkipsCallsUntilThen(): void
    {
        $clock = new MockClock('2026-11-25T09:00:00Z');
        FreshBooksTestTools::settings($this->db(), $clock);
        FreshBooksTestTools::connect($this->db(), $clock, $this->vault());
        $calls = [];
        $routes = ['GET ' . FreshBooksTestTools::ACCOUNT_PATH . 'invoices/invoices#1' => new MockResponse('{"errors":[{"message":"fikcyjny limit"}]}', ['http_code' => 429,'response_headers' => ['Retry-After: 120']])];
        $sync = FreshBooksTestTools::sync($this->db(), $clock, FreshBooksTestTools::client($routes, $calls), $this->vault());
        try {
            $sync->pollInvoices();
            self::fail('429 przeszło bez błędu.');
        } catch (\App\Connector\FreshBooks\FreshBooksException $exception) {
            self::assertSame('rate_limited', $exception->error->value);
        }
        $counter = $this->db()->fetchAllAssociative("SELECT connector, bucket, blocked_until FROM rate_counter WHERE connector='freshbooks'");
        self::assertSame([['connector' => 'freshbooks','bucket' => 'freshbooks.api','blocked_until' => '2026-11-25 09:02:00+00']], $counter);
        self::assertSame(['GET ' . FreshBooksTestTools::ACCOUNT_PATH . 'invoices/invoices#1'], $calls);
        // Przed `blocked_until` konektor nie woła FreshBooks; kursor stoi.
        $clock->sleep(60);
        try {
            $sync->pollInvoices();
            self::fail('Wywołanie przed blocked_until.');
        } catch (\App\Rate\RateLimitExceededException) {
        }
        self::assertCount(1, $calls);
        self::assertNull($this->db()->fetchOne("SELECT last_success_at FROM sync_cursor WHERE connector='freshbooks' AND stream='invoices'"));
        $clock->sleep(61);
        $calls = [];
        $sync = FreshBooksTestTools::sync($this->db(), $clock, FreshBooksTestTools::client(FreshBooksTestTools::backfillRoutes(), $calls), $this->vault(), self::RATES);
        $sync->pollInvoices();
        self::assertCount(2, $calls);
        $retry = $this->db()->fetchOne("SELECT retry_after_s FROM connector_call WHERE connector='freshbooks' AND http_status=429");
        self::assertSame(120, $retry);
        EvidenceArtefacts::write('db/freshbooks-rate-limit-429-rate_counter.csv', EvidenceArtefacts::csv($counter));
    }

    private function vault(): Vault
    {
        $vault = self::getContainer()->get(Vault::class);
        self::assertInstanceOf(Vault::class, $vault);
        return $vault;
    }
}
