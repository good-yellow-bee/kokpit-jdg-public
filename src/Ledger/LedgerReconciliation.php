<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Config\ConnectorConfiguration;
use App\Connector\FreshBooks\FreshBooksInvoice;
use App\Fx\FxRateProvider;
use App\Fx\FxRateUnavailable;
use App\Invoice\VatFx;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;

/**
 * Widok „Uzgodnienia” (docs/modules/ewidencja.md, „Uzgodnienia”): import, KSeF Subject1, FreshBooks i duplikaty między
 * źródłami. Każdy wiersz raportu ma kontrolę, dokument, wynik i szczegół; eksport CSV z panelu. Przed przełączeniem to
 * tylko raport, bez zapisu wierszy.
 */
final readonly class LedgerReconciliation
{
    public const COLUMNS = ['check','document_number','ksef_number','sources','status','detail'];

    public function __construct(private Connection $db, private FxRateProvider $rates, private ConnectorConfiguration $configuration) {}

    /**
     * @param list<FreshBooksInvoice>|null $freshbooks faktury FreshBooks roku; null, gdy FreshBooks nie jest połączony
     * @return list<array{check:string,document_number:string,ksef_number:string,sources:string,status:string,detail:string}>
     */
    public function report(int $year, ?array $freshbooks): array
    {
        return array_merge($this->import($year), $this->subject1($year), $freshbooks === null ? [self::row('freshbooks', '', '', '', 'not_connected', 'połącz FreshBooks w panelu')] : $this->freshbooks($year, $freshbooks), $this->duplicates($year));
    }

    /** @param list<array{check:string,document_number:string,ksef_number:string,sources:string,status:string,detail:string}> $rows */
    public static function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new \RuntimeException('Nie można zbudować CSV.');
        }
        fputcsv($handle, self::COLUMNS, ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_values($row), ';', '"', '');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        return $csv === false ? '' : $csv;
    }

    /** @return list<array{check:string,document_number:string,ksef_number:string,sources:string,status:string,detail:string}> */
    private function import(int $year): array
    {
        $environment = $this->configuration->environment();
        $details = $this->db->fetchOne("SELECT details FROM audit_log WHERE action='ledger.imported' AND environment=? AND (details->>'year')::int=? ORDER BY sequence DESC LIMIT 1", [$environment, $year]);
        $ledger = $this->db->fetchAssociative("SELECT count(*) AS row_count, coalesce(sum(amount_pln),0) AS total FROM revenue_entry WHERE environment=? AND year=? AND source='import'", [$environment, $year]);
        $rows = is_array($ledger) && is_numeric($ledger['row_count']) ? (int) $ledger['row_count'] : 0;
        $total = is_array($ledger) && is_scalar($ledger['total']) ? (string) $ledger['total'] : '0';
        if (!is_string($details)) {
            return [self::row('import', '', '', 'import', $rows === 0 ? 'ok' : 'difference', sprintf('ewidencja %d wierszy, K_8 %s; brak importu pliku', $rows, $total))];
        }
        $audit = json_decode($details, true);
        $inFile = is_array($audit) && is_int($audit['rows_in_file'] ?? null) ? $audit['rows_in_file'] : -1;
        $fileTotal = is_array($audit) && is_string($audit['total_in_file'] ?? null) ? $audit['total_in_file'] : null;
        $equal = $inFile === $rows && ($fileTotal === null || BigDecimal::of($fileTotal)->isEqualTo($total));
        return [self::row('import', '', '', 'import', $equal ? 'ok' : 'difference', sprintf('plik %d wierszy, K_8 %s; ewidencja %d wierszy, K_8 %s', $inFile, $fileTotal ?? '?', $rows, $total))];
    }

    /**
     * Każda przyjęta faktura `VAT` z Subject1: dokładnie jeden zestaw wierszy `regular` po `ksef_number` (albo `invoice_id`), z dowolnego `source`.
     *
     * @return list<array{check:string,document_number:string,ksef_number:string,sources:string,status:string,detail:string}>
     */
    private function subject1(int $year): array
    {
        $result = [];
        $invoices = $this->db->fetchAllAssociative(
            "SELECT i.id, i.invoice_number, i.ksef_number, i.revenue_date,
                    (SELECT string_agg(DISTINCT r.source, ',' ORDER BY r.source) FROM revenue_entry r WHERE r.kind='regular' AND (r.invoice_id=i.id OR (r.environment=i.environment AND r.ksef_number=i.ksef_number))) AS sources,
                    (SELECT string_agg(DISTINCT r.revenue_date::text, ',') FROM revenue_entry r WHERE r.kind='regular' AND (r.invoice_id=i.id OR (r.environment=i.environment AND r.ksef_number=i.ksef_number))) AS dates
             FROM invoice i WHERE i.environment=? AND i.direction='sale' AND i.kind='VAT' AND i.ksef_number IS NOT NULL AND i.status IN ('accepted','corrected','superseded')
             AND extract(year FROM coalesce(i.revenue_date, i.issue_date))=? ORDER BY i.issue_date, i.invoice_number",
            [$this->configuration->environment(), $year],
        );
        foreach ($invoices as $invoice) {
            $sources = is_string($invoice['sources']) ? $invoice['sources'] : '';
            $dates = is_string($invoice['dates']) ? $invoice['dates'] : '';
            $status = match (true) {
                $sources === '' => 'missing',
                str_contains($sources, ',') => 'duplicate',
                is_string($invoice['revenue_date']) && $dates !== $invoice['revenue_date'] => 'date_difference',
                default => 'ok',
            };
            $result[] = self::row('ksef_subject1', self::str($invoice['invoice_number']), self::str($invoice['ksef_number']), $sources, $status, $status === 'date_difference' ? sprintf('K_3 %s, faktura %s', $dates, self::str($invoice['revenue_date'])) : '');
        }
        return $result;
    }

    /**
     * Każda faktura FreshBooks roku (bez szkiców i usuniętych) przeliczona kursem PIT z dnia przed datą przychodu wobec wierszy `regular` po numerze
     * albo po fakturze z KSeF o tym `freshbooks_id`.
     *
     * @param list<FreshBooksInvoice> $invoices
     * @return list<array{check:string,document_number:string,ksef_number:string,sources:string,status:string,detail:string}>
     */
    private function freshbooks(int $year, array $invoices): array
    {
        $environment = $this->configuration->environment();
        $result = [];
        foreach ($invoices as $invoice) {
            if ($invoice->isDraft() || $invoice->deleted || (int) substr($invoice->createDate, 0, 4) !== $year) {
                continue;
            }
            $ksef = $this->db->fetchAssociative("SELECT id, ksef_number FROM invoice WHERE environment=? AND direction='sale' AND freshbooks_id=? AND ksef_number IS NOT NULL", [$environment, $invoice->id]);
            $rows = $this->db->fetchAllAssociative(
                "SELECT source, revenue_date, amount_pln FROM revenue_entry WHERE environment=? AND kind='regular' AND (document_number=? OR (?::uuid IS NOT NULL AND invoice_id=?::uuid))",
                [$environment, $invoice->number, $ksef === false ? null : $ksef['id'], $ksef === false ? null : $ksef['id']],
            );
            $ksefNumber = $ksef !== false && is_string($ksef['ksef_number']) ? $ksef['ksef_number'] : '';
            $sources = array_values(array_unique(array_map(static fn(array $row): string => self::str($row['source']), $rows)));
            sort($sources);
            if ($rows === []) {
                $result[] = self::row('freshbooks', $invoice->number, $ksefNumber, '', 'missing', 'brak wierszy w ewidencji');
                continue;
            }
            if (count($sources) > 1) {
                $result[] = self::row('freshbooks', $invoice->number, $ksefNumber, implode(',', $sources), 'duplicate', '');
                continue;
            }
            // Data przychodu jak w FreshBooksLedger: `create_date` albo wcześniejsza zapłata (art. 14 ust. 1c).
            $paid = $this->db->fetchOne('SELECT min(paid_on) FROM payment WHERE environment=? AND freshbooks_invoice_id=?', [$environment, $invoice->id]);
            $revenueDate = is_string($paid) && $paid < $invoice->createDate ? $paid : $invoice->createDate;
            $expected = $invoice->net;
            if ($invoice->currency !== 'PLN') {
                try {
                    $expected = VatFx::convert($invoice->net, $this->rates->getRate($invoice->currency, $revenueDate)->mid);
                } catch (FxRateUnavailable) {
                    $result[] = self::row('freshbooks', $invoice->number, $ksefNumber, implode(',', $sources), 'fx_rate_pending', '');
                    continue;
                }
            }
            $actual = BigDecimal::zero();
            $dates = [];
            foreach ($rows as $row) {
                $actual = $actual->plus(self::str($row['amount_pln']));
                $dates[] = self::str($row['revenue_date']);
            }
            $dates = array_values(array_unique($dates));
            $status = !$actual->isEqualTo($expected) ? 'amount_difference' : ($dates !== [$revenueDate] ? 'date_difference' : 'ok');
            $detail = match ($status) {
                'amount_difference' => sprintf('FreshBooks %s, ewidencja %s', $expected, (string) $actual->toScale(2)),
                'date_difference' => sprintf('FreshBooks %s, K_3 %s', $revenueDate, implode(',', $dates)),
                default => '',
            };
            $result[] = self::row('freshbooks', $invoice->number, $ksefNumber, implode(',', $sources), $status, $detail);
        }
        return $result;
    }

    /**
     * Ten sam `ksef_number` albo `document_number` w wierszach `regular` dwóch źródeł: alert.
     *
     * @return list<array{check:string,document_number:string,ksef_number:string,sources:string,status:string,detail:string}>
     */
    private function duplicates(int $year): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT min(document_number) AS document_number, coalesce(ksef_number,'') AS ksef_number, string_agg(DISTINCT source, ',' ORDER BY source) AS sources
             FROM revenue_entry WHERE environment=? AND year=? AND kind='regular'
             GROUP BY coalesce(ksef_number, 'doc:' || document_number), coalesce(ksef_number,'') HAVING count(DISTINCT source) > 1 ORDER BY 1",
            [$this->configuration->environment(), $year],
        );
        if ($rows === []) {
            return [self::row('duplicates', '', '', '', 'ok', 'brak dokumentu w dwóch źródłach')];
        }
        return array_map(static fn(array $row): array => self::row('duplicates', self::str($row['document_number']), self::str($row['ksef_number']), self::str($row['sources']), 'duplicate', 'alert'), $rows);
    }

    /** @return array{check:string,document_number:string,ksef_number:string,sources:string,status:string,detail:string} */
    private static function row(string $check, string $document, string $ksef, string $sources, string $status, string $detail): array
    {
        return ['check' => $check,'document_number' => $document,'ksef_number' => $ksef,'sources' => $sources,'status' => $status,'detail' => $detail];
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : throw new \LogicException('Niepoprawna wartość wiersza.');
    }
}
