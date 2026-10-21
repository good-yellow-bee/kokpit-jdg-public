<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Config\ConnectorConfiguration;
use App\Time\BusinessDate;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;

/**
 * Cash-flow na 90 dni (D-88): otwarte obowiązki z kwotą (urząd, ZUS, WHT) wobec należności z faktur sprzedaży
 * i niezapłaconych kosztów. Tylko odczyt, bez przeliczania walut: saldo liczy się tylko w PLN, inne waluty stoją osobno.
 * Rezerwę „do odłożenia dziś” liczy panel „Podatki” (D-76). Wyciąg mBanku nie ma salda, więc rachunki pokazują tylko
 * ruch z zapisanych operacji, poza saldem (D-110).
 */
final readonly class CashFlow
{
    /** Horyzont widoku w dniach od dziś; stała panelu, nie parametr podatkowy. */
    public const HORIZON_DAYS = 90;
    public const MAX_ROWS = 200;
    /** Faktura sprzedaży, której zapłata może jeszcze wpłynąć; korekty (KOR) zmieniają kwotę przez `payment` i nie są należnością. */
    private const RECEIVABLE = "i.direction='sale' AND i.kind IN ('VAT','ZAL','ROZ','UPR') AND i.status IN ('accepted','corrected')";
    /** Koszt firmy do zapłaty: bez `paid_on` i bez oznaczenia jako prywatny albo cudzy; czyta też dopasowanie wyciągu (D-110). */
    public const PAYABLE = "i.direction='cost' AND i.paid_on IS NULL AND (i.cost_review IS NULL OR i.cost_review IN ('business','disputed'))";

    public function __construct(private Connection $db, private ConnectorConfiguration $configuration, private BusinessDate $date) {}

    /**
     * @return array{today: string, until: string, obligations: list<array<string, mixed>>, by_month: list<array<string, mixed>>, without_amount: list<array<string, mixed>>,
     *     receivables: list<array<string, mixed>>, receivable_totals: list<array<string, mixed>>, payables: list<array<string, mixed>>, payable_totals: list<array<string, mixed>>,
     *     bank: list<array<string, mixed>>, bank_until: ?string,
     *     pln: array{receivables: string, obligations: string, payables: string, balance: string}}
     */
    public function summary(): array
    {
        $environment = $this->configuration->environment();
        $today = $this->date->today();
        $until = $this->date->midnight()->modify(sprintf('+%d days', self::HORIZON_DAYS))->format('Y-m-d');
        // Otwarte z kwotą do końca horyzontu, także zaległe; kwota do zapłaty pomniejszona o wpłatę częściową.
        $obligations = $this->db->fetchAllAssociative(
            'SELECT id, type, period_start, due_date, due_date < ? AS overdue, greatest(amount_due - COALESCE(amount_paid, 0), 0) AS amount
             FROM obligation WHERE environment=? AND status=\'open\' AND amount_due IS NOT NULL AND due_date <= ? ORDER BY due_date, type, id',
            [$today, $environment, $until],
        );
        $byMonth = $this->db->fetchAllAssociative(
            "SELECT CASE WHEN due_date < ? THEN 'zaległe' ELSE to_char(due_date, 'YYYY-MM') END AS month, type, count(*) AS items, sum(greatest(amount_due - COALESCE(amount_paid, 0), 0)) AS amount
             FROM obligation WHERE environment=? AND status='open' AND amount_due IS NOT NULL AND due_date <= ? GROUP BY 1, type ORDER BY 1 DESC, type",
            [$today, $environment, $until],
        );
        // Kolejność: zaległe na górze, potem miesiące rosnąco (sortowanie po tekście stawia „zaległe” za cyframi).
        usort($byMonth, static fn(array $a, array $b): int => [$a['month'] !== 'zaległe', $a['month'], $a['type']] <=> [$b['month'] !== 'zaległe', $b['month'], $b['type']]);
        $receivables = $this->db->fetchAllAssociative(
            'SELECT i.id, i.invoice_number, i.issue_date, i.ksef_number, c.name AS counterparty_name, i.currency, i.gross_total, COALESCE(p.paid, 0) AS paid, i.gross_total - COALESCE(p.paid, 0) AS outstanding
             FROM invoice i JOIN counterparty c ON c.id=i.counterparty_id
             LEFT JOIN (SELECT invoice_id, currency, sum(amount) AS paid FROM payment WHERE environment=? AND invoice_id IS NOT NULL GROUP BY invoice_id, currency) p ON p.invoice_id=i.id AND p.currency=i.currency
             WHERE i.environment=? AND ' . self::RECEIVABLE . ' AND i.gross_total - COALESCE(p.paid, 0) > 0
             ORDER BY i.issue_date, i.invoice_number LIMIT ' . self::MAX_ROWS,
            [$environment, $environment],
        );
        $receivableTotals = $this->db->fetchAllAssociative(
            'SELECT i.currency, count(*) AS items, sum(i.gross_total - COALESCE(p.paid, 0)) AS outstanding
             FROM invoice i LEFT JOIN (SELECT invoice_id, currency, sum(amount) AS paid FROM payment WHERE environment=? AND invoice_id IS NOT NULL GROUP BY invoice_id, currency) p ON p.invoice_id=i.id AND p.currency=i.currency
             WHERE i.environment=? AND ' . self::RECEIVABLE . ' AND i.gross_total - COALESCE(p.paid, 0) > 0 GROUP BY i.currency ORDER BY i.currency',
            [$environment, $environment],
        );
        $payableTotals = $this->db->fetchAllAssociative(
            'SELECT i.currency, count(*) AS items, sum(i.gross_total) AS outstanding FROM invoice i WHERE i.environment=? AND ' . self::PAYABLE . ' GROUP BY i.currency ORDER BY i.currency',
            [$environment],
        );
        $obligationsPln = self::sum(array_column($obligations, 'amount'));
        $receivablesPln = self::currency($receivableTotals, 'PLN');
        $payablesPln = self::currency($payableTotals, 'PLN');
        return [
            'today' => $today,
            'until' => $until,
            'obligations' => $obligations,
            'by_month' => $byMonth,
            'without_amount' => $this->db->fetchAllAssociative(
                "SELECT id, type, period_start, due_date FROM obligation WHERE environment=? AND status='open' AND amount_due IS NULL AND due_date BETWEEN ? AND ?
                 AND type IN ('zus','ryczalt_advance','vat_jpk_v7m','wht_payment','health_reconciliation') ORDER BY due_date, type, id",
                [$environment, $today, $until],
            ),
            'receivables' => $receivables,
            'receivable_totals' => $receivableTotals,
            'payables' => $this->db->fetchAllAssociative(
                'SELECT i.id, i.invoice_number, i.issue_date, c.name AS counterparty_name, i.currency, i.gross_total, i.cost_review FROM invoice i JOIN counterparty c ON c.id=i.counterparty_id
                 WHERE i.environment=? AND ' . self::PAYABLE . ' ORDER BY i.issue_date, i.invoice_number LIMIT ' . self::MAX_ROWS,
                [$environment],
            ),
            'payable_totals' => $payableTotals,
            'bank' => $this->db->fetchAllAssociative(
                'SELECT account, currency, min(booked_on) AS first_booked, max(booked_on) AS last_booked, count(*) AS items, COALESCE(sum(amount) FILTER (WHERE amount > 0), 0.00) AS inflow,
                 COALESCE(sum(amount) FILTER (WHERE amount < 0), 0.00) AS outflow, sum(amount) AS net FROM bank_statement_line WHERE environment=? GROUP BY account, currency ORDER BY account, currency',
                [$environment],
            ),
            'bank_until' => self::date($this->db->fetchOne("SELECT max(period_to) FROM bank_statement WHERE environment=? AND status='confirmed'", [$environment])),
            'pln' => [
                'receivables' => (string) $receivablesPln,
                'obligations' => (string) $obligationsPln,
                'payables' => (string) $payablesPln,
                'balance' => (string) $receivablesPln->minus($obligationsPln)->minus($payablesPln)->toScale(2),
            ],
        ];
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /** @param list<mixed> $amounts */
    private static function sum(array $amounts): BigDecimal
    {
        $sum = BigDecimal::zero()->toScale(2);
        foreach ($amounts as $amount) {
            $sum = $sum->plus(BigDecimal::of(is_string($amount) || is_int($amount) ? $amount : '0'));
        }
        return $sum->toScale(2);
    }

    /** @param list<array<string, mixed>> $totals */
    private static function currency(array $totals, string $currency): BigDecimal
    {
        foreach ($totals as $row) {
            if ($row['currency'] === $currency) {
                return self::sum([$row['outstanding']]);
            }
        }
        return BigDecimal::zero()->toScale(2);
    }
}
