<?php

declare(strict_types=1);

namespace App\Invoice;

use App\Config\ConnectorConfiguration;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;

/**
 * Raport przejścia, kroki 1 i 2 (docs/modules/ksef.md, „Stan obecny i przejście”): liczba i suma Twoich faktur z KSeF
 * w roku oraz seria P_2 per `kind`. Seria to P_2 bez końcowego ciągu cyfr, numer to ten ciąg jako liczba.
 * `first_issue_date` to proponowany `ksef.sales_transition_on`; zapisujesz go sam po potwierdzeniu (Q-10).
 */
final readonly class TransitionReport
{
    public function __construct(private Connection $db, private ConnectorConfiguration $configuration) {}

    /**
     * @return array{year:int,count:int,gross_by_currency:array<string,string>,first_issue_date:?string,series:list<array{kind:string,series:string,count:int,highest:?int,last_number:string,last_issue_date:string}>}
     */
    public function build(int $year): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT kind, invoice_number, issue_date, currency, gross_total FROM invoice WHERE environment=? AND direction='sale' AND ksef_number IS NOT NULL AND status NOT IN ('cancelled_before_send','rejected') AND issue_date BETWEEN ? AND ? ORDER BY issue_date, invoice_number",
            [$this->configuration->environment(), sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)],
        );
        $totals = [];
        $series = [];
        $first = null;
        foreach ($rows as $row) {
            [$kind, $number, $issued, $currency, $gross] = [self::text($row['kind']), self::text($row['invoice_number']), self::text($row['issue_date']), self::text($row['currency']), self::text($row['gross_total'])];
            $first ??= $issued;
            $totals[$currency] = (string) BigDecimal::of($totals[$currency] ?? '0.00')->plus(BigDecimal::of($gross))->toScale(2);
            [$prefix, $value] = self::split($number);
            $key = $kind . "\0" . $prefix;
            $current = $series[$key] ?? ['kind' => $kind, 'series' => $prefix, 'count' => 0, 'highest' => null, 'last_number' => $number, 'last_issue_date' => $issued];
            $current['count']++;
            if ($value !== null && ($current['highest'] === null || $value > $current['highest'])) {
                $current['highest'] = $value;
            }
            // Wiersze idą po `issue_date`, więc ostatni nadpisuje ostatnią fakturę serii.
            $current['last_number'] = $number;
            $current['last_issue_date'] = $issued;
            $series[$key] = $current;
        }
        ksort($totals);
        ksort($series);
        return ['year' => $year, 'count' => count($rows), 'gross_by_currency' => $totals, 'first_issue_date' => $first, 'series' => array_values($series)];
    }

    /** @return array{string,?int} seria i numer, np. `FV/2026/` i 12 dla `FV/2026/0012` */
    public static function split(string $number): array
    {
        if (preg_match('/^(.*?)(\d+)$/sD', $number, $match) !== 1) {
            return [$number, null];
        }
        return [$match[1], (int) ltrim($match[2], '0')];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : throw new \LogicException('Niepoprawny wiersz faktury.');
    }
}
