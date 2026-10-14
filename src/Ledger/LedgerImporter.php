<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Audit\AuditLog;
use App\Config\ConnectorConfiguration;
use App\Storage\FileStore;
use App\Time\BusinessDate;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Import ewidencji roku ze źródła `import` (F2.1). Każdy import czyta pełną ewidencję roku:
 * znane K_1 muszą być identyczne, nowe K_1 są dopisywane. Odmowa nie zapisuje żadnego wiersza.
 */
final readonly class LedgerImporter
{
    private const LOCK_NAMESPACE = 7071402;
    public function __construct(private Connection $db, private ClockInterface $clock, private BusinessDate $businessDate, private LedgerFileParser $parser, private FileStore $files, private AuditLog $audit, private ConnectorConfiguration $configuration) {}
    public function import(string $bytes, int $year, ?string $originalName = null): LedgerImportResult
    {
        if ($year < 2026 || $year > 2100) {
            throw new LedgerImportRefused([sprintf('rok %d poza zakresem ewidencji w programie (od 2026)', $year)]);
        }
        $rows = $this->parser->parse($bytes);
        $environment = $this->configuration->environment();
        return $this->db->transactional(function () use ($rows, $year, $environment, $bytes, $originalName): LedgerImportResult {
            // Blokada roku: `lp` nadawane i porównywane w jednej kolejności zapisu.
            $this->db->executeQuery('SELECT pg_advisory_xact_lock(?, ?)', [self::LOCK_NAMESPACE, $year]);
            $problems = [];
            foreach ($rows as $row) {
                if ((int) substr($row->revenueDate, 0, 4) !== $year) {
                    $problems[] = sprintf('K_1 %d: K_3 %s poza rokiem %d', $row->lp, $row->revenueDate, $year);
                }
            }
            $others = $this->db->fetchFirstColumn("SELECT DISTINCT source FROM revenue_entry WHERE environment=? AND year=? AND source<>'import' ORDER BY source", [$environment, $year]);
            if ($others !== []) {
                $problems[] = sprintf('rok %d ma wiersze innego źródła (%s); import działa tylko przed nimi', $year, implode(', ', array_map(static fn(mixed $source): string => is_string($source) ? $source : '?', $others)));
            }
            $known = [];
            foreach ($this->db->fetchAllAssociative("SELECT lp,entry_date,revenue_date,document_number,ksef_number,counterparty_country,counterparty_tax_id,amount_pln,rate_pct,remarks FROM revenue_entry WHERE environment=? AND year=? AND source='import' ORDER BY lp", [$environment, $year]) as $existing) {
                $stored = self::stored($existing);
                $known[(int) $stored['K_1']] = $stored;
            }
            $byLp = [];
            foreach ($rows as $row) {
                $byLp[$row->lp] = $row;
            }
            foreach ($known as $lp => $stored) {
                if (!isset($byLp[$lp])) {
                    $problems[] = sprintf('K_1 %d jest w ewidencji, a brak go w pliku', $lp);
                    continue;
                }
                foreach ($byLp[$lp]->canonical() as $field => $value) {
                    if ($field === 'K_2' && $value === null) {
                        continue;
                    }
                    if ($stored[$field] !== $value) {
                        $problems[] = sprintf('K_1 %d: %s w pliku „%s”, w ewidencji „%s”', $lp, $field, $value ?? '', $stored[$field] ?? '');
                    }
                }
            }
            if ($problems !== []) {
                throw new LedgerImportRefused($problems);
            }
            $today = $this->businessDate->today();
            $recordedAt = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
            $added = [];
            foreach ($rows as $row) {
                if (isset($known[$row->lp])) {
                    continue;
                }
                $id = (string) UuidV7::generate($this->clock->now());
                // Puste K_2 to dzień importu w Europe/Warsaw, nigdy wcześniejszy (D-23).
                $this->db->insert('revenue_entry', ['id' => $id,'environment' => $environment,'year' => $year,'lp' => $row->lp,'entry_date' => $row->entryDate ?? $today,'revenue_date' => $row->revenueDate,'document_number' => $row->documentNumber,'ksef_number' => $row->ksefNumber,'counterparty_country' => $row->counterpartyCountry,'counterparty_tax_id' => $row->counterpartyTaxId,'amount_pln' => (string) $row->amountPln->toScale(2),'rate_pct' => (string) $row->ratePct->toScale(2),'remarks' => $row->remarks,'source' => 'import','kind' => 'regular','recorded_at' => $recordedAt]);
                $added[] = $id;
            }
            $evidence = null;
            if ($added !== []) {
                $xml = LedgerFileParser::isXml($bytes);
                // Dowód pochodzenia K_2: plik źródłowy w `FileStore`; retencja od terminu JPK_EWP za rok (rok + 1).
                $this->files->put($bytes, $environment, 'evidence', $xml ? 'application/xml' : 'text/csv', 'revenue_entry', $added[0], $year + 1);
                $evidence = hash('sha256', $bytes);
                $this->audit->append('ledger.imported', ['year' => $year,'source' => 'import','format' => $xml ? 'jpk_ewp_xml' : 'csv','file_sha256' => $evidence,'rows_in_file' => count($rows),'rows_added' => count($added),'original_name' => $originalName], $environment, 'revenue_entry', $added[0]);
            }
            return new LedgerImportResult($year, count($rows), count($added), $evidence, ...$this->summary($environment, $year));
        });
    }
    /** @return array{int,string,array<string,array<string,array{rows:int,sum:string}>>} */
    public function summary(string $environment, int $year): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT lp,entry_date,revenue_date,document_number,ksef_number,counterparty_country,counterparty_tax_id,amount_pln,rate_pct,remarks FROM revenue_entry WHERE environment=? AND year=? ORDER BY lp', [$environment, $year]);
        $dump = '';
        $sums = [];
        foreach ($rows as $row) {
            $canonical = self::stored($row);
            $dump .= json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            $month = substr((string) $canonical['K_3'], 0, 7);
            $rate = (string) $canonical['K_9'];
            $current = $sums[$month][$rate] ?? ['rows' => 0,'sum' => '0.00'];
            $sums[$month][$rate] = ['rows' => $current['rows'] + 1,'sum' => (string) BigDecimal::of($current['sum'])->plus((string) $canonical['K_8'])->toScale(2)];
        }
        ksort($sums);
        foreach ($sums as &$byRate) {
            uksort($byRate, static fn(string $a, string $b): int => BigDecimal::of($a)->compareTo($b));
        }
        unset($byRate);
        return [count($rows), hash('sha256', $dump), $sums];
    }
    /**
     * @param array<string,mixed> $row
     * @return array<string,?string>
     */
    private static function stored(array $row): array
    {
        $text = static fn(mixed $value): ?string => $value === null ? null : (is_scalar($value) ? (string) $value : throw new \LogicException('Niepoprawna wartość wiersza.'));
        return ['K_1' => $text($row['lp']),'K_2' => $text($row['entry_date']),'K_3' => $text($row['revenue_date']),'K_4' => $text($row['document_number']),'K_5' => $text($row['ksef_number']),'K_6' => $text($row['counterparty_country']),'K_7' => $text($row['counterparty_tax_id']),'K_8' => (string) BigDecimal::of((string) $text($row['amount_pln']))->toScale(2),'K_9' => (string) BigDecimal::of((string) $text($row['rate_pct']))->toScale(2),'K_10' => $text($row['remarks'])];
    }
}
