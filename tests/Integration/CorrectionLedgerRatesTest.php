<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\CorrectionLedger;
use App\Setting\SettingKeys;
use App\Tests\Support\ChatTestTools;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\ObligationTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/**
 * Wiersze `correction` z KOR (docs/modules/ewidencja.md, „Wiersz `correction` z KOR”): stawki, wybór wiersza korygowanego,
 * K_6/K_7 i kurs PIT, kolejność lat. Faktury, pozycje i kursy wstawione wprost; dane fikcyjne.
 */
final class CorrectionLedgerRatesTest extends DatabaseTestCase
{
    private MockClock $clock;
    private string $user;
    private string $counterparty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2027-01-10T09:00:00Z');
        $this->user = F1TestTools::owner($this->db(), $this->clock);
        $this->counterparty = ChatTestTools::counterparty($this->db(), $this->clock, 'test', 'Fikcyjny klient sp. z o.o.', 'PL');
    }

    public function testEachRateGetsRowLinkedToItsCorrectedEntryInRateOrder(): void
    {
        ObligationTestTools::settings($this->db(), $this->clock)->set(SettingKeys::LEDGER_AUTO_ENTRIES, true, '2026-01-01', null);
        $invoice = $this->sale('FV/1/11/2026', '2026-11-05');
        $entry12 = $this->regular(1, '2026-11-05', 'FV/1/11/2026', '12.00', ['invoice_id' => $invoice]);
        $entry85 = $this->regular(2, '2026-11-05', 'FV/1/11/2026', '8.50', ['invoice_id' => $invoice]);
        $kor = $this->kor('FK/1/12/2026', '2026-12-05', 'PLN', ['corrects_invoice_id' => $invoice]);
        // Pozycje 8,50% przed 12,00%: kolejność wierszy wynika ze stawek, nie z pozycji.
        $this->line($kor, 1, true, '500.05', '8.50');
        $this->line($kor, 1, false, '400.00', '8.50');
        $this->line($kor, 2, true, '1000.00', '12.00');
        $this->line($kor, 2, false, '1100.00', '12.00');

        $written = $this->ledger()->close($kor, $this->user);
        self::assertSame([['12.00', '100.00', $entry12], ['8.50', '-100.05', $entry85]], array_map(fn(string $id): array => array_values($this->row($id, 'rate_pct, amount_pln, corrects_entry_id')), $written));
        self::assertSame(['rows' => 2, 'reason' => 'other'], $this->auditDetails());
        // Wszystkie dokumenty 12.2026 mają wiersze: miesiąc dostaje `ledger_closed_at` od razu po korekcie.
        self::assertIsString($this->db()->fetchOne("SELECT ledger_closed_at FROM period WHERE period_start='2026-12-01'"));
    }

    public function testZeroDifferenceRateIsSkippedAndBuyerSnapshotFillsCounterparty(): void
    {
        $kor = $this->kor('FK/2/12/2026', '2026-12-07', 'PLN', ['corrected_invoice_number' => 'FV/99/11/2026', 'ksef_number' => 'FIKCYJNY-KSEF-KOR-2', 'buyer_snapshot' => json_encode(['nip' => F1TestTools::CLIENT_NIP], JSON_THROW_ON_ERROR)]);
        $this->line($kor, 1, true, '1000.00', '12.00');
        $this->line($kor, 1, false, '1000.00', '12.00');
        $this->line($kor, 2, true, '300.00', '8.50');
        $this->line($kor, 2, false, '350.00', '8.50');
        $ledger = $this->ledger();
        self::assertSame([['id' => $kor, 'invoice_number' => 'FK/2/12/2026', 'ksef_number' => 'FIKCYJNY-KSEF-KOR-2', 'issue_date' => '2026-12-07', 'corrected_invoice_number' => 'FV/99/11/2026']], $ledger->pending());

        $written = $ledger->close($kor, $this->user, 'arithmetic_error');
        self::assertCount(1, $written);
        self::assertSame(['revenue_date' => '2026-12-07', 'rate_pct' => '8.50', 'amount_pln' => '50.00', 'counterparty_country' => 'PL', 'counterparty_tax_id' => F1TestTools::CLIENT_NIP, 'ksef_number' => 'FIKCYJNY-KSEF-KOR-2', 'corrects_entry_id' => null], $this->row($written[0], 'revenue_date, rate_pct, amount_pln, counterparty_country, counterparty_tax_id, ksef_number, corrects_entry_id'));
        self::assertSame(['rows' => 1, 'reason' => 'arithmetic_error'], $this->auditDetails());
    }

    public function testCorrectedEntryCounterpartyAndRateWinOnlyWhenPresent(): void
    {
        $rateA = $this->fxRate('2026-11-04', '4.200000');
        $rateB = $this->fxRate('2026-12-04', '4.300000');
        $this->regular(1, '2026-11-05', 'FV/3/11/2026', '12.00', ['counterparty_country' => 'DE', 'counterparty_tax_id' => 'DE999999999']);
        $this->regular(2, '2026-11-05', 'FV/3/11/2026', '8.50', ['fx_rate_id' => $rateA]);
        $kor = $this->kor('FK/3/12/2026', '2026-12-05', 'EUR', ['corrected_invoice_number' => 'FV/3/11/2026', 'buyer_snapshot' => json_encode(['nip' => F1TestTools::CLIENT_NIP], JSON_THROW_ON_ERROR)]);
        $this->line($kor, 1, true, '100.00', '12.00');
        $this->line($kor, 1, false, '90.00', '12.00');
        $this->line($kor, 2, true, '200.00', '8.50');
        $this->line($kor, 2, false, '210.00', '8.50');

        $written = $this->ledger()->close($kor, $this->user, 'other', [], $rateB);
        $columns = 'rate_pct, amount_pln, original_amount, original_currency, counterparty_country, counterparty_tax_id, fx_rate_id';
        self::assertSame([
            ['rate_pct' => '12.00', 'amount_pln' => '-43.00', 'original_amount' => '-10.00', 'original_currency' => 'EUR', 'counterparty_country' => 'DE', 'counterparty_tax_id' => 'DE999999999', 'fx_rate_id' => $rateB],
            ['rate_pct' => '8.50', 'amount_pln' => '42.00', 'original_amount' => '10.00', 'original_currency' => 'EUR', 'counterparty_country' => 'PL', 'counterparty_tax_id' => F1TestTools::CLIENT_NIP, 'fx_rate_id' => $rateA],
        ], array_map(fn(string $id): array => $this->row($id, $columns), $written));
    }

    public function testUnknownPitRateIsRefused(): void
    {
        $kor = $this->kor('FK/5/12/2026', '2026-12-05', 'EUR', []);
        $this->line($kor, 1, true, '100.00', '12.00');
        $this->line($kor, 1, false, '90.00', '12.00');
        try {
            $this->ledger()->close($kor, $this->user, 'other', [], (string) UuidV7::generate());
            self::fail('Korekta z nieznanym kursem PIT.');
        } catch (\DomainException $e) {
            self::assertSame('Nieznany kurs PIT.', $e->getMessage());
        }
    }

    public function testRowsAreWrittenYearByYearInAscendingOrder(): void
    {
        $this->regular(1, '2027-01-03', 'FV/4/2026', '12.00', ['year' => 2027]);
        $this->regular(1, '2026-12-20', 'FV/4/2026', '8.50');
        $kor = $this->kor('FK/1/01/2027', '2027-01-08', 'PLN', ['corrected_invoice_number' => 'FV/4/2026']);
        $this->line($kor, 1, true, '100.00', '12.00');
        $this->line($kor, 1, false, '120.00', '12.00');
        $this->line($kor, 2, true, '100.00', '8.50');
        $this->line($kor, 2, false, '130.00', '8.50');

        $written = $this->ledger()->close($kor, $this->user, 'arithmetic_error');
        self::assertSame([[2026, '2026-12-20', '8.50'], [2027, '2027-01-03', '12.00']], array_map(fn(string $id): array => array_values($this->row($id, 'year, revenue_date, rate_pct')), $written));
    }

    private function ledger(): CorrectionLedger
    {
        return new CorrectionLedger($this->db(), F1TestTools::writer($this->db(), $this->clock), ObligationTestTools::settings($this->db(), $this->clock), F1TestTools::audit($this->db(), $this->clock), ConnectorTestTools::configuration(false));
    }

    private function sale(string $number, string $issued): string
    {
        return ChatTestTools::sale($this->db(), $this->clock, 'test', $this->counterparty, $number, $issued, 'PLN', '1000.00', '230.00', '1000.00', 'accepted');
    }

    /** @param array<string,string> $extra */
    private function kor(string $number, string $issued, string $currency, array $extra): string
    {
        $id = (string) UuidV7::generate();
        $stamp = $this->clock->now()->format('c');
        $this->db()->insert('invoice', $extra + ['id' => $id, 'environment' => 'test', 'direction' => 'sale', 'kind' => 'KOR', 'invoice_number' => $number, 'counterparty_id' => $this->counterparty, 'currency' => $currency,
            'issue_date' => $issued, 'net_total' => '0.00', 'vat_total' => '0.00', 'gross_total' => '0.00', 'status' => 'accepted', 'created_at' => $stamp, 'updated_at' => $stamp]);
        return $id;
    }

    private function line(string $invoice, int $no, bool $before, string $net, string $rate): void
    {
        $stamp = $this->clock->now()->format('c');
        $this->db()->insert('invoice_line', ['id' => (string) UuidV7::generate(), 'invoice_id' => $invoice, 'line_no' => $no, 'state_before' => $before ? 'true' : 'false', 'description' => 'Fikcyjna usługa ' . $no,
            'quantity' => '1', 'unit' => 'szt.', 'net_amount' => $net, 'vat_code' => '23', 'ryczalt_rate_pct' => $rate, 'created_at' => $stamp, 'updated_at' => $stamp]);
    }

    /** @param array<string,int|string> $extra */
    private function regular(int $lp, string $revenueDate, string $number, string $rate, array $extra = []): string
    {
        $id = (string) UuidV7::generate();
        $this->db()->insert('revenue_entry', $extra + ['id' => $id, 'environment' => 'test', 'year' => 2026, 'lp' => $lp, 'entry_date' => $revenueDate, 'revenue_date' => $revenueDate, 'document_number' => $number,
            'amount_pln' => '1000.00', 'rate_pct' => $rate, 'source' => 'import', 'kind' => 'regular', 'recorded_at' => $this->clock->now()->format('c')]);
        return $id;
    }

    private function fxRate(string $date, string $mid): string
    {
        $id = (string) UuidV7::generate();
        $this->db()->insert('fx_rate', ['id' => $id, 'source' => 'nbp', 'table_no' => 'FIKC/' . $date, 'currency' => 'EUR', 'effective_date' => $date, 'mid' => $mid, 'fetched_at' => $this->clock->now()->format('c')]);
        return $id;
    }

    /** @return array<string,mixed> */
    private function row(string $id, string $columns): array
    {
        $row = $this->db()->fetchAssociative('SELECT ' . $columns . ' FROM revenue_entry WHERE id=?', [$id]);
        return is_array($row) ? $row : throw new \LogicException('Brak wiersza.');
    }

    /** @return array<mixed> */
    private function auditDetails(): array
    {
        $details = $this->db()->fetchOne("SELECT details FROM audit_log WHERE action='ledger.correction_closed'");
        $decoded = json_decode(is_string($details) ? $details : 'null', true, 16, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : throw new \LogicException('Brak wpisu.');
    }
}
