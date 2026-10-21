<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Bank\BankLine;
use App\Bank\BankLineKind;
use App\Fixtures\FictionalIdentifiers;
use App\Tests\Support\BankTestTools;
use App\Tests\Support\ChatTestTools;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\ObligationTestTools;
use Brick\Math\BigDecimal;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/** Propozycje dopasowań wierszy wyciągu (D-108): reguły, weta z tytułu i jednoznaczność w obie strony. Dane fikcyjne. */
final class BankLineMatcherTest extends DatabaseTestCase
{
    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-05T09:00:00Z');
        $settings = ObligationTestTools::settings($this->db(), $this->clock);
        $settings->set('company.zus_account', FictionalIdentifiers::account(2), '2026-01-01', null, 'fictional');
        $settings->set('company.tax_micro_account', FictionalIdentifiers::account(4), '2026-01-01', null, 'fictional');
    }

    public function testZusAndTaxObligationsByOperationTypeOrAccount(): void
    {
        $zus = $this->obligation('zus', 'zus.monthly', '2026-08-01', '2026-09-21', '1926.76');
        $health = $this->obligation('health_reconciliation', 'health_reconciliation.yearly', '2025-01-01', '2026-05-20', '321.00');
        $advance = $this->obligation('ryczalt_advance', 'ryczalt_advance.monthly', '2026-08-01', '2026-09-22', '1234.00');
        $wht = $this->obligation('wht_payment', 'wht_payment.monthly', '2026-08-01', '2026-09-07', '77.00');
        $this->obligation('vat_jpk_v7m', 'vat_jpk_v7m.monthly', '2026-08-01', '2026-09-25', '2300.00');
        $lines = [
            'zus' => $this->line(BankLineKind::Zus, '-1926.76', 'ZAKŁAD UBEZPIECZEŃ SPOŁECZNYCH'),
            'health' => $this->line(BankLineKind::Outgoing, '-321.00', 'SKŁADKA', FictionalIdentifiers::account(2)),
            'advance' => $this->line(BankLineKind::Tax, '-1234.00', 'URZĄD SKARBOWY, PIT-28 ZRYCZAŁTOWANY ZA 26M08'),
            'wht' => $this->line(BankLineKind::Outgoing, '-77.00', 'URZĄD SKARBOWY', FictionalIdentifiers::account(4)),
            'vat' => $this->line(BankLineKind::Tax, '-2300.00', 'URZĄD SKARBOWY, VAT-7 ZA SIERPIEŃ'),
        ];
        self::assertSame([
            'zus' => ['zus_amount', $zus, 'obowiązek zus za 2026-08-01, termin 2026-09-21'],
            'health' => ['zus_amount', $health, 'obowiązek health_reconciliation za 2025-01-01, termin 2026-05-20'],
            'advance' => ['tax_amount', $advance, 'obowiązek ryczalt_advance za 2026-08-01, termin 2026-09-22'],
            'wht' => ['tax_amount', $wht, 'obowiązek wht_payment za 2026-08-01, termin 2026-09-07'],
        ], $this->propose($lines));
        $proposal = BankTestTools::matcher($this->db(), $this->clock)->propose([$lines['zus']])[$lines['zus']->hash];
        self::assertSame([null, null, ['obligation:' . $zus]], [$proposal->invoiceId, $proposal->paymentId, $proposal->targets()]);
        self::assertSame($lines['zus']->hash . '|zus_amount|||' . $zus, $proposal->token($lines['zus']->hash));
    }

    public function testObligationRulesRefuseRefundsOtherCurrencyEarlyPaymentsAndInternalTransfers(): void
    {
        $this->obligation('zus', 'zus.monthly', '2026-08-01', '2026-09-21', '100.00');
        $this->obligation('ryczalt_advance', 'ryczalt_advance.monthly', '2026-09-01', '2026-10-20', '200.00');
        $this->obligation('zus', 'zus.monthly', '2026-07-01', '2026-08-20', '300.00', 'done');
        self::assertSame([], $this->propose(['refund' => $this->line(BankLineKind::Zus, '100.00', 'ZWROT')]));
        self::assertSame([], $this->propose(['eur' => $this->line(BankLineKind::Zus, '-100.00', 'ZUS', currency: 'EUR')]));
        self::assertSame([], $this->propose([
            'internal' => $this->line(BankLineKind::Internal, '-100.00', 'PP/PRZELEW VAT', FictionalIdentifiers::account(2)),
            'outgoing' => $this->line(BankLineKind::Outgoing, '-100.00', 'ZUS', FictionalIdentifiers::account(3)),
            'before period' => $this->line(BankLineKind::Tax, '-200.00', 'PPE', bookedOn: '2026-08-31'),
            'wrong group' => $this->line(BankLineKind::Tax, '-100.00', 'URZĄD SKARBOWY'),
            'closed' => $this->line(BankLineKind::Zus, '-300.00', 'ZUS'),
        ]));
        $onFirstDay = $this->line(BankLineKind::Tax, '-200.00', 'PPE', bookedOn: '2026-09-01');
        self::assertSame(['x' => ['tax_amount']], array_map(static fn(array $row): array => [$row[0]], $this->propose(['x' => $onFirstDay])));
    }

    public function testTitleSymbolAndPeriodVetoCandidates(): void
    {
        $august = $this->obligation('ryczalt_advance', 'ryczalt_advance.monthly', '2026-08-01', '2026-09-22', '500.00');
        $quarter = $this->obligation('ryczalt_advance', 'ryczalt_advance.quarterly', '2026-07-01', '2026-10-20', '600.00');
        $wht = $this->obligation('wht_payment', 'wht_payment.monthly', '2026-08-01', '2026-09-07', '700.00');
        self::assertSame(['august' => $august, 'quarter' => $quarter, 'cit' => $wht], array_map(static fn(array $row): ?string => $row[1], $this->propose([
            'august' => $this->line(BankLineKind::Tax, '-500.00', 'PIT-28 26m08'),
            'quarter' => $this->line(BankLineKind::Tax, '-600.00', 'PPE 26K03'),
            'cit' => $this->line(BankLineKind::Tax, '-700.00', 'CIT-10Z 26M08'),
        ])));
        self::assertSame([], $this->propose([
            'vat symbol' => $this->line(BankLineKind::Tax, '-500.00', 'VAT-7 26M08'),
            'vat-7k symbol' => $this->line(BankLineKind::Tax, '-600.00', 'VAT-7K 26K03'),
            'other symbol' => $this->line(BankLineKind::Tax, '-700.00', 'PPE 26M08'),
            'other period' => $this->line(BankLineKind::Tax, '-500.00', 'PIT-28 26M07'),
            'month for quarter' => $this->line(BankLineKind::Tax, '-600.00', 'PPE 26M07'),
        ]));
        $first = $this->obligation('ryczalt_advance', 'ryczalt_advance.monthly', '2026-08-01', '2026-09-01', '800.00');
        $later = $this->obligation('wht_payment', 'wht_payment.monthly', '2026-08-01', '2026-09-30', '800.00');
        self::assertSame(['cit after vetoed' => $later], array_map(static fn(array $row): ?string => $row[1], $this->propose(['cit after vetoed' => $this->line(BankLineKind::Tax, '-800.00', 'CIT-10Z')])));
        $this->obligation('ryczalt_advance', 'ryczalt_advance.monthly', '2026-07-01', '2026-08-20', '900.00');
        $august900 = $this->obligation('ryczalt_advance', 'ryczalt_advance.monthly', '2026-08-01', '2026-09-22', '900.00');
        self::assertSame(['august after vetoed july' => $august900], array_map(static fn(array $row): ?string => $row[1], $this->propose(['august after vetoed july' => $this->line(BankLineKind::Tax, '-900.00', 'PPE 26M08')])));
        self::assertNotSame($first, $later);
        self::assertCount(1, $this->propose(['symbol inside word' => $this->line(BankLineKind::Tax, '-700.00', 'XPPE CIT-10Z')]));
        self::assertSame([], $this->propose(['lowercase other period' => $this->line(BankLineKind::Tax, '-500.00', 'pit-28 26m07')]));
        self::assertCount(1, $this->propose(['period inside word' => $this->line(BankLineKind::Tax, '-500.00', 'NR A26M07 B26M075')]));
    }

    public function testAmbiguityInEitherDirectionGivesNoProposal(): void
    {
        $this->obligation('zus', 'zus.monthly', '2026-07-01', '2026-08-20', '100.00');
        $this->obligation('zus', 'zus.monthly', '2026-08-01', '2026-09-21', '100.00');
        $september = $this->obligation('zus', 'zus.monthly', '2026-09-01', '2026-10-20', '250.00');
        self::assertSame([], $this->propose(['two targets' => $this->line(BankLineKind::Zus, '-100.00', 'ZUS')]));
        self::assertSame([], $this->propose([
            'first' => $this->line(BankLineKind::Zus, '-250.00', 'ZUS'),
            'second' => $this->line(BankLineKind::Zus, '-250.00', 'ZUS', bookedOn: '2026-09-16'),
        ]));
        self::assertSame(['one' => ['zus_amount', $september, 'obowiązek zus za 2026-09-01, termin 2026-10-20']], $this->propose(['one' => $this->line(BankLineKind::Zus, '-250.00', 'ZUS', bookedOn: '2026-09-16')]));
    }

    public function testIncomingMatchesAcceptedInvoiceByNumberAndAmountWithItsPayment(): void
    {
        $client = ChatTestTools::counterparty($this->db(), $this->clock, 'test', 'Fikcyjny klient', 'PL');
        $seven = ChatTestTools::sale($this->db(), $this->clock, 'test', $client, 'FV 7/2026', '2026-09-01', 'PLN', '10000.00', '2300.00', '10000.00', 'accepted');
        $payment = $this->payment('FB-1', '12300.00', 'PLN', ['invoice_id' => $seven]);
        $eight = ChatTestTools::sale($this->db(), $this->clock, 'test', $client, 'FV 8/2026', '2026-09-01', 'PLN', '2000.00', '460.00', '2000.00', 'accepted');
        $this->payment('FB-2', '2460.00', 'PLN', ['invoice_id' => $eight]);
        $this->payment('FB-3', '2460.00', 'PLN', ['invoice_id' => $eight]);
        $this->payment('FB-4', '2460.00', 'EUR', ['invoice_id' => $eight]);
        ChatTestTools::sale($this->db(), $this->clock, 'test', $client, 'FV 9/2026', '2026-09-01', 'PLN', '100.00', '23.00', '100.00', 'corrected');
        ChatTestTools::sale($this->db(), $this->clock, 'test', $client, 'FV 10/2026', '2026-09-01', 'EUR', '100.00', '0.00', '430.00', 'accepted');
        $correction = ChatTestTools::sale($this->db(), $this->clock, 'test', $client, 'KOR 11/2026', '2026-09-01', 'PLN', '50.00', '0.00', '50.00', 'draft');
        $this->db()->update('invoice', ['kind' => 'KOR', 'status' => 'accepted'], ['id' => $correction]);
        ChatTestTools::sale($this->db(), $this->clock, 'prod', ChatTestTools::counterparty($this->db(), $this->clock, 'prod', 'Fikcyjny klient', 'PL'), 'FV 12/2026', '2026-09-01', 'PLN', '40.00', '0.00', '40.00', 'accepted');
        $lines = [
            'seven' => $this->line(BankLineKind::Incoming, '12300.00', 'FIKCYJNA SPÓŁKA, fv 7/2026'),
            'eight' => $this->line(BankLineKind::Incoming, '2460.00', 'ZAPŁATA ZA FV/FV 8/2026/A'),
            'other number' => $this->line(BankLineKind::Incoming, '12300.00', 'FV 7/20260 XFV 7/2026'),
            'corrected' => $this->line(BankLineKind::Incoming, '123.00', 'FV 9/2026'),
            'other currency' => $this->line(BankLineKind::Incoming, '100.00', 'FV 10/2026'),
            'correction' => $this->line(BankLineKind::Incoming, '50.00', 'KOR 11/2026'),
            'other environment' => $this->line(BankLineKind::Incoming, '40.00', 'FV 12/2026'),
            'outgoing' => $this->line(BankLineKind::Outgoing, '12300.00', 'FV 7/2026'),
        ];
        $proposals = BankTestTools::matcher($this->db(), $this->clock)->propose(array_values($lines));
        self::assertSame([$lines['seven']->hash, $lines['eight']->hash], array_keys($proposals));
        $first = $proposals[$lines['seven']->hash];
        self::assertSame(['invoice_title_amount', 'faktura FV 7/2026 i jej płatność', $seven, $payment, null], [$first->rule->value, $first->label, $first->invoiceId, $first->paymentId, $first->obligationId]);
        self::assertSame(['invoice:' . $seven, 'payment:' . $payment], $first->targets());
        self::assertSame($lines['seven']->hash . '|invoice_title_amount|' . $seven . '|' . $payment . '|', $first->token($lines['seven']->hash));
        $second = $proposals[$lines['eight']->hash];
        self::assertSame(['faktura FV 8/2026', $eight, null], [$second->label, $second->invoiceId, $second->paymentId]);
        self::assertSame([], $this->propose(['twice' => $this->line(BankLineKind::Incoming, '12300.00', 'FV 7/2026'), 'again' => $this->line(BankLineKind::Incoming, '12300.00', 'FV 7/2026', bookedOn: '2026-09-16')]));
    }

    public function testIncomingWithTwoInvoiceNumbersOfSameAmountIsAmbiguous(): void
    {
        $client = ChatTestTools::counterparty($this->db(), $this->clock, 'test', 'Fikcyjny klient', 'PL');
        ChatTestTools::sale($this->db(), $this->clock, 'test', $client, 'FV 1/2026', '2026-09-01', 'PLN', '100.00', '23.00', '100.00', 'accepted');
        ChatTestTools::sale($this->db(), $this->clock, 'test', $client, 'FV 2/2026', '2026-09-01', 'PLN', '100.00', '23.00', '100.00', 'accepted');
        self::assertSame([], $this->propose(['both' => $this->line(BankLineKind::Incoming, '123.00', 'FV 1/2026 FV 2/2026')]));
        self::assertCount(1, $this->propose(['one' => $this->line(BankLineKind::Incoming, '123.00', 'FV 2/2026')]));
    }

    public function testIncomingMatchesPaymentOfLedgerEntryByDocumentNumber(): void
    {
        $entry = (string) UuidV7::generate();
        $stamp = $this->clock->now()->format('c');
        $this->db()->insert('revenue_entry', ['id' => $entry, 'environment' => 'test', 'year' => 2026, 'lp' => 1, 'entry_date' => '2026-01-15', 'revenue_date' => '2026-01-10', 'document_number' => 'RACH 3/2025', 'amount_pln' => '900.00', 'rate_pct' => '12.00', 'source' => 'import', 'kind' => 'regular', 'recorded_at' => $stamp]);
        $payment = $this->payment('FB-9', '900.00', 'PLN', ['revenue_entry_id' => $entry]);
        $this->payment('FB-10', '900.00', 'PLN');
        $line = $this->line(BankLineKind::Incoming, '900.00', 'ZAPŁATA RACH 3/2025');
        self::assertSame(['x' => ['payment_title_amount', null, 'płatność dokumentu RACH 3/2025']], $this->propose(['x' => $line]));
        $match = BankTestTools::matcher($this->db(), $this->clock)->propose([$line])[$line->hash];
        self::assertSame([null, $payment, ['payment:' . $payment]], [$match->invoiceId, $match->paymentId, $match->targets()]);
        self::assertSame([], $this->propose(['x' => $this->line(BankLineKind::Incoming, '900.00', 'ZAPŁATA RACH 13/2025')]));
        $this->db()->insert('revenue_entry', ['id' => $second = (string) UuidV7::generate(), 'environment' => 'test', 'year' => 2026, 'lp' => 2, 'entry_date' => '2026-01-15', 'revenue_date' => '2026-01-10', 'document_number' => 'RACH 4/2025', 'amount_pln' => '950.00', 'rate_pct' => '12.00', 'source' => 'import', 'kind' => 'regular', 'recorded_at' => $stamp]);
        $this->payment('FB-11', '950.00', 'PLN', ['revenue_entry_id' => $second]);
        $this->payment('FB-12', '950.00', 'PLN', ['revenue_entry_id' => $second]);
        self::assertSame([], $this->propose(['two payments' => $this->line(BankLineKind::Incoming, '950.00', 'RACH 4/2025')]));
    }

    public function testNumbersCompareWithoutCaseIncludingPolishLetters(): void
    {
        $client = ChatTestTools::counterparty($this->db(), $this->clock, 'test', 'Fikcyjny klient', 'PL');
        ChatTestTools::sale($this->db(), $this->clock, 'test', $client, 'FV Ż/1', '2026-09-01', 'PLN', '10.00', '0.00', '10.00', 'accepted');
        ChatTestTools::sale($this->db(), $this->clock, 'test', $client, 'fv ż/2', '2026-09-01', 'PLN', '20.00', '0.00', '20.00', 'accepted');
        self::assertCount(2, $this->propose([
            'lower title' => $this->line(BankLineKind::Incoming, '10.00', 'zapłata fv ż/1'),
            'lower number' => $this->line(BankLineKind::Incoming, '20.00', 'ZAPŁATA FV Ż/2'),
        ]));
    }

    /**
     * @param array<string,BankLine> $lines
     * @return array<string,array{string,?string,string}> klucz → reguła, obowiązek, opis propozycji
     */
    private function propose(array $lines): array
    {
        $proposals = BankTestTools::matcher($this->db(), $this->clock)->propose(array_values($lines));
        $result = [];
        foreach ($lines as $key => $line) {
            if (isset($proposals[$line->hash])) {
                $match = $proposals[$line->hash];
                $result[$key] = [$match->rule->value, $match->obligationId, $match->label];
            }
        }
        return $result;
    }

    private function line(BankLineKind $kind, string $amount, string $description, ?string $counterparty = null, string $bookedOn = '2026-09-15', string $currency = 'PLN'): BankLine
    {
        return new BankLine(hash('sha256', $kind->value . $amount . $description . $bookedOn . $currency . ($counterparty ?? '')), FictionalIdentifiers::account(1), $bookedOn, BigDecimal::of($amount), $currency, null, $description, $counterparty, $kind, false);
    }

    private function obligation(string $type, string $ruleKey, string $periodStart, string $due, string $amount, string $status = 'open'): string
    {
        $id = (string) UuidV7::generate();
        $stamp = $this->clock->now()->format('c');
        $this->db()->insert('obligation', ['id' => $id, 'environment' => 'test', 'type' => $type, 'rule_key' => $ruleKey, 'period_start' => $periodStart, 'nominal_due_date' => $due, 'due_date' => $due, 'amount_due' => $amount, 'status' => $status, 'dedupe_key' => 'fikcyjny-' . $id, 'created_at' => $stamp, 'updated_at' => $stamp]
            + ($status === 'open' ? [] : ['done_at' => $stamp]));
        return $id;
    }

    /** @param array<string,string> $link */
    private function payment(string $freshbooksId, string $amount, string $currency, array $link = []): string
    {
        $id = (string) UuidV7::generate();
        $stamp = $this->clock->now()->format('c');
        $this->db()->insert('payment', ['id' => $id, 'environment' => 'test', 'freshbooks_payment_id' => $freshbooksId, 'freshbooks_invoice_id' => 'FIKC', 'paid_on' => '2026-09-14', 'amount' => $amount, 'currency' => $currency, 'created_at' => $stamp, 'updated_at' => $stamp]
            + $link + ($link === [] ? [] : ['matched_at' => $stamp]));
        return $id;
    }
}
