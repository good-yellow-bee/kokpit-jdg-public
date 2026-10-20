<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Setting\SettingKeys;
use App\Tests\Support\ChatTestTools;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\FreshBooksTestTools;
use App\Tests\Support\ObligationTestTools;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/**
 * Różnica kursowa przy zapłacie (docs/modules/ewidencja.md, „Wiersz `fx_difference`”): warunki wierszy źródłowych,
 * podział proporcjonalny z precyzją pośrednią 10 miejsc (HALF_UP) i kurs NBP zapisany tylko tej płatności. Dane i kursy fikcyjne.
 */
final class FxDifferenceSplitTest extends DatabaseTestCase
{
    private MockClock $clock;
    private string $counterparty;
    private string $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-12-10T09:00:00Z');
        $this->counterparty = ChatTestTools::counterparty($this->db(), $this->clock, 'test', 'Fikcyjny klient', 'DE');
        $this->invoice = $this->sale('FV/1/11/2026');
    }

    /** @return iterable<string,array{string,list<string>,string,list<string>}> */
    public static function splits(): iterable
    {
        // Część 0,0049999996 € × 1 zł daje 0,00 zł: zaokrąglenie do 9 miejsc albo do groszy z 3 miejsc dałoby 0,01 zł.
        yield 'share just below half grosz' => ['0.01', ['12499999.00', '12500001.00'], '5.000000', ['0.01']];
        // 1249,99999 € × 0,000004 zł = 0,00499999996 zł: 10 miejsc daje 0,0050000000, czyli 0,01 zł.
        yield 'product rounded at tenth place' => ['2500.00', ['124999999.00', '125000001.00'], '4.000004', ['0.01', '0.01']];
        // Część 0,00249999995 € zaokrąglona do 10 miejsc to 0,0025000000; × 2 zł daje 0,01 zł.
        yield 'share rounded at tenth place' => ['0.01', ['49999999.00', '150000001.00'], '6.000000', ['0.01', '0.02']];
    }

    /**
     * @param list<string> $originals
     * @param list<string> $expected
     */
    #[DataProvider('splits')]
    public function testPartialPaymentSplitUsesTenPlaceIntermediates(string $amount, array $originals, string $actualRate, array $expected): void
    {
        $mid = $this->fxRate('2026-11-04', '4.000000');
        foreach ($originals as $lp => $original) {
            $this->source($lp + 1, 'EUR', $mid, $original, ['12.00', '8.50'][$lp]);
        }
        $payment = $this->payment($amount, $actualRate);
        self::assertSame('posted', FreshBooksTestTools::fx($this->db(), $this->clock)->post($payment));
        self::assertSame($expected, $this->db()->fetchFirstColumn("SELECT amount_pln FROM revenue_entry WHERE kind='fx_difference' ORDER BY lp"));
    }

    public function testDifferenceRowKeepsGroszeInvoiceAndClosesCompleteMonth(): void
    {
        ObligationTestTools::settings($this->db(), $this->clock)->set(SettingKeys::LEDGER_AUTO_ENTRIES, true, '2026-01-01', null);
        $this->source(1, 'EUR', $this->fxRate('2026-11-04', '4.000000'), '100.00');
        $payment = $this->payment('100.00', '4.123400');
        self::assertSame('posted', FreshBooksTestTools::fx($this->db(), $this->clock)->post($payment));
        $row = $this->db()->fetchAssociative("SELECT amount_pln, invoice_id, payment_id, revenue_date FROM revenue_entry WHERE kind='fx_difference'");
        self::assertSame(['amount_pln' => '12.34', 'invoice_id' => $this->invoice, 'payment_id' => $payment, 'revenue_date' => '2026-11-20'], $row);
        self::assertIsString($this->db()->fetchOne("SELECT ledger_closed_at FROM period WHERE period_start='2026-11-01'"));
    }

    public function testSourceInOtherCurrencyOrWithoutRateIsRefused(): void
    {
        $this->source(1, 'USD', $this->fxRate('2026-11-04', '4.000000'), '100.00');
        self::assertSame('source_without_fx', FreshBooksTestTools::fx($this->db(), $this->clock)->post($this->payment('100.00', '4.100000')));

        $this->invoice = $this->sale('FV/2/11/2026');
        $this->source(2, 'EUR', null, '100.00');
        self::assertSame('source_without_fx', FreshBooksTestTools::fx($this->db(), $this->clock)->post($this->payment('100.00', '4.100000')));
        self::assertSame(0, F1TestTools::count($this->db(), "SELECT count(*) FROM revenue_entry WHERE kind='fx_difference'"));
    }

    public function testNbpRateIsStoredOnlyOnPostedPayment(): void
    {
        $this->source(1, 'EUR', $this->fxRate('2026-11-04', '4.000000'), '100.00');
        $other = $this->payment('50.00', null);
        $payment = $this->payment('100.00', null);
        $fx = FreshBooksTestTools::fx($this->db(), $this->clock, ['EUR' => ['2026-11-19' => '4.100000']]);
        self::assertSame('posted', $fx->post($payment));
        self::assertNotNull($this->db()->fetchOne('SELECT fx_rate_id FROM payment WHERE id=?', [$payment]));
        self::assertNull($this->db()->fetchOne('SELECT fx_rate_id FROM payment WHERE id=?', [$other]));
    }

    private function sale(string $number): string
    {
        return ChatTestTools::sale($this->db(), $this->clock, 'test', $this->counterparty, $number, '2026-11-05', 'EUR', '1000.00', '0.00', '4000.00', 'accepted');
    }

    private function source(int $lp, string $currency, ?string $fxRateId, string $original, string $rate = '12.00'): void
    {
        $this->db()->insert('revenue_entry', ['id' => (string) UuidV7::generate(), 'environment' => 'test', 'year' => 2026, 'lp' => $lp, 'entry_date' => '2026-11-05', 'revenue_date' => '2026-11-05', 'document_number' => 'FV/' . $lp . '/11/2026',
            'counterparty_country' => 'DE', 'amount_pln' => '1.00', 'rate_pct' => $rate, 'source' => 'freshbooks', 'kind' => 'regular', 'invoice_id' => $this->invoice, 'original_amount' => $original, 'original_currency' => $currency,
            'fx_rate_id' => $fxRateId, 'recorded_at' => $this->clock->now()->format('c')]);
    }

    private function payment(string $amount, ?string $actualRate): string
    {
        $id = (string) UuidV7::generate();
        $stamp = $this->clock->now()->format('c');
        $this->db()->insert('payment', ['id' => $id, 'environment' => 'test', 'invoice_id' => $this->invoice, 'freshbooks_payment_id' => 'FIKC-PAY-' . substr($id, -6), 'paid_on' => '2026-11-20', 'amount' => $amount, 'currency' => 'EUR',
            'actual_rate' => $actualRate, 'matched_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp]);
        return $id;
    }

    private function fxRate(string $date, string $mid): string
    {
        $id = (string) UuidV7::generate();
        $this->db()->insert('fx_rate', ['id' => $id, 'source' => 'nbp', 'table_no' => 'FIKC/' . $date, 'currency' => 'EUR', 'effective_date' => $date, 'mid' => $mid, 'fetched_at' => $this->clock->now()->format('c')]);
        return $id;
    }
}
