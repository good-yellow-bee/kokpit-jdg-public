<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\RevenueDocument;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\FreshBooksTestTools;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/**
 * Wiersz `fx_difference` (docs/tax-rules.md, „Płatności, różnice kursowe i metoda kasowa”; docs/modules/ewidencja.md,
 * „Wiersz `fx_difference`”). Kursy, kwoty i numery są fikcyjne.
 */
final class FxDifferenceTest extends DatabaseTestCase
{
    public function testFxDifferenceMatchesTaxRulesExample(): void
    {
        $clock = new MockClock('2026-03-25T09:00:00Z');
        $rates = ['EUR' => ['2026-03-09' => '4.2500']];
        $this->source($clock, $rates, 'FB-2026-0201', '2026-03-10', ['12.00' => '1000.00']);
        // Przewalutowanie w dniu `paid_on` po 4,2800 (kurs z raportu Stripe wpisany ręcznie).
        $payment = $this->payment($clock, '601', '2026-03-20', '1000.00', 'FB-2026-0201', '4.2800');
        self::assertSame('posted', FreshBooksTestTools::fx($this->db(), $clock, $rates)->post($payment));
        self::assertSame('already_posted', FreshBooksTestTools::fx($this->db(), $clock, $rates)->post($payment));
        $row = $this->db()->fetchAssociative("SELECT revenue_date, document_number, ksef_number, counterparty_country, counterparty_tax_id, amount_pln, rate_pct, remarks, source, payment_id FROM revenue_entry WHERE kind='fx_difference'");
        self::assertIsArray($row);
        self::assertSame(['2026-03-20', '601', null, 'DE', '999999999', '30.00', '12.00', 'FB-2026-0201', 'freshbooks', $payment], array_values($row));
        // Przykład z docs/tax-rules.md: 1 000,00 EUR × 4,2500 = 4 250,00 zł; × 4,2800 = 4 280,00 zł; różnica +30,00 zł.
        $source = $this->db()->fetchOne("SELECT amount_pln FROM revenue_entry WHERE kind='regular'");
        $expected = "przychód 4250.00\nwpływ 4280.00\nróżnica 30.00\n";
        $actual = sprintf("przychód %s\nwpływ %s\nróżnica %s\n", is_string($source) ? $source : '', '4280.00', is_string($row['amount_pln']) ? $row['amount_pln'] : '');
        self::assertSame($expected, $actual);
        EvidenceArtefacts::write('golden/fx-difference.diff', $expected === $actual ? '' : "--- oczekiwane\n+++ wynik\n-" . $expected . '+' . $actual);
    }

    public function testFxDifferenceSplitsPartialPaymentAcrossSourceRows(): void
    {
        $clock = new MockClock('2026-03-25T09:00:00Z');
        $rates = ['EUR' => ['2026-03-09' => '4.2500','2026-03-19' => '4.3000']];
        $this->source($clock, $rates, 'FB-2026-0202', '2026-03-10', ['12.00' => '600.00','8.50' => '400.00']);
        // Płatność częściowa 500,00 EUR, kurs NBP z 19.03: (4,3000 − 4,2500) × 500 = 25,00 zł, dzielone 60/40.
        $payment = $this->payment($clock, '602', '2026-03-20', '500.00', 'FB-2026-0202', null);
        self::assertSame('posted', FreshBooksTestTools::fx($this->db(), $clock, $rates)->post($payment));
        $rows = $this->db()->fetchAllAssociative("SELECT rate_pct, amount_pln FROM revenue_entry WHERE kind='fx_difference' ORDER BY rate_pct DESC");
        self::assertSame([['rate_pct' => '12.00','amount_pln' => '15.00'], ['rate_pct' => '8.50','amount_pln' => '10.00']], $rows);
        self::assertSame('2026-03-19', $this->db()->fetchOne('SELECT f.effective_date FROM payment p JOIN fx_rate f ON f.id=p.fx_rate_id WHERE p.id=?', [$payment]));
    }

    public function testFxDifferenceYearBoundaryGoesToPaymentYear(): void
    {
        $clock = new MockClock('2027-01-15T09:00:00Z');
        $rates = ['EUR' => ['2026-12-18' => '4.3000','2027-01-08' => '4.2900']];
        // Faktura z 20.12.2026 (niedziela, kurs z piątku 18.12), zapłata 10.01.2027 (niedziela, kurs z piątku 8.01).
        $this->source($clock, $rates, 'FB-2026-0299', '2026-12-20', ['12.00' => '1000.00']);
        $payment = $this->payment($clock, '699', '2027-01-10', '1000.00', 'FB-2026-0299', null);
        self::assertSame('posted', FreshBooksTestTools::fx($this->db(), $clock, $rates)->post($payment));
        $rows = $this->db()->fetchAllAssociative("SELECT year, lp, revenue_date AS k_3, document_number AS k_4, ksef_number AS k_5, counterparty_country AS k_6, counterparty_tax_id AS k_7, amount_pln AS k_8, rate_pct AS k_9, remarks AS k_10, kind, payment_id
            FROM revenue_entry ORDER BY year, lp");
        self::assertCount(2, $rows);
        self::assertSame([2027, 1, '2027-01-10', '699', null, 'DE', '999999999', '-10.00', '12.00', 'FB-2026-0299', 'fx_difference', $payment], array_values($rows[1]));
        EvidenceArtefacts::write('db/fx-difference-year-boundary-revenue_entry.csv', EvidenceArtefacts::csv($rows));
    }

    /**
     * @param array<string,array<string,string>> $rates
     * @param array<string,string> $netByRate
     */
    private function source(MockClock $clock, array $rates, string $number, string $revenueDate, array $netByRate): void
    {
        $rate = F1TestTools::fxRates($this->db(), $clock, $rates)->getRate('EUR', $revenueDate);
        F1TestTools::writer($this->db(), $clock)->write(new RevenueDocument('freshbooks', null, $number, null, 'DE', '999999999', $revenueDate, 'EUR', $rate, $netByRate));
    }

    private function payment(MockClock $clock, string $id, string $paidOn, string $amount, string $number, ?string $actualRate): string
    {
        $entry = $this->db()->fetchOne("SELECT id FROM revenue_entry WHERE document_number=? AND kind='regular' ORDER BY lp LIMIT 1", [$number]);
        self::assertIsString($entry);
        $paymentId = (string) UuidV7::generate($clock->now());
        $now = $clock->now()->format('c');
        $this->db()->insert('payment', ['id' => $paymentId,'environment' => 'test','revenue_entry_id' => $entry,'freshbooks_payment_id' => $id,'freshbooks_invoice_id' => '1' . $id,'paid_on' => $paidOn,'amount' => $amount,'currency' => 'EUR',
            'gateway' => 'Stripe','actual_rate' => $actualRate,'matched_at' => $now,'created_at' => $now,'updated_at' => $now]);
        return $paymentId;
    }
}
