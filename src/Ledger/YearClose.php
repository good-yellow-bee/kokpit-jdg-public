<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Calendar\ObligationRules;
use App\Config\ConnectorConfiguration;
use App\Panel\CsvExport;
use App\Storage\FileStore;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Kreator zamknięcia roku (D-86): obowiązki roczne za rok N z terminami w roku N+1, stan 12 miesięcy, złożenia roku
 * z plikami, przychód per miesiąc i stawka oraz ustawienia bez wiersza od 1.01 roku N+1. Tylko odczyt;
 * `archive()` składa ZIP roku z zestawieniami CSV i plikami złożeń (`xml_sent`, `upo`) z `FileStore`.
 */
final readonly class YearClose
{
    public const FIRST_YEAR = 2026;
    /** Ewidencja w archiwum: te same kolumny co eksport `/ledger?format=csv` (D-78). */
    private const LEDGER_COLUMNS = ['lp','entry_date','revenue_date','document_number','ksef_number','counterparty_country','counterparty_tax_id','amount_pln','rate_pct','source','kind'];
    private const EXTENSIONS = ['application/xml' => 'xml', 'text/xml' => 'xml', 'application/pdf' => 'pdf'];

    public function __construct(private Connection $db, private ConnectorConfiguration $configuration, private FileStore $files) {}

    /** @return list<string> */
    public static function annualRules(): array
    {
        return array_keys(array_filter(ObligationRules::RULES, static fn(array $rule): bool => in_array($rule['cadence'], ['yearly', 'yearly_entity'], true)));
    }

    /**
     * @return array{year: int, obligations: list<array<string, mixed>>, open_obligations: int, months: list<array<string, mixed>>, open_months: int,
     *     filings: list<array<string, mixed>>, revenue: list<array<string, mixed>>, settings: list<array<string, mixed>>}
     */
    public function checklist(int $year): array
    {
        $environment = $this->configuration->environment();
        $obligations = $this->obligations($environment, $year);
        $months = $this->db->fetchAllAssociative(
            "SELECT to_char(m, 'YYYY-MM') AS month, COALESCE(p.status, 'open') AS status, p.closed_at, p.correction_required_at
             FROM generate_series(make_date(?::int, 1, 1), make_date(?::int, 12, 1), interval '1 month') m
             LEFT JOIN period p ON p.environment=? AND p.period_start=m::date ORDER BY m",
            [$year, $year, $environment],
        );
        return [
            'year' => $year,
            'obligations' => $obligations,
            'open_obligations' => count(array_filter($obligations, static fn(array $row): bool => $row['status'] === 'open')),
            'months' => $months,
            'open_months' => count(array_filter($months, static fn(array $row): bool => $row['status'] !== 'closed')),
            'filings' => $this->filings($environment, $year),
            'revenue' => $this->revenue($environment, $year),
            'settings' => $this->db->fetchAllAssociative(
                'SELECT key, max(valid_from) AS latest_valid_from, bool_or(valid_from >= make_date(?::int, 1, 1)) AS next_year_ready FROM setting GROUP BY key ORDER BY key',
                [$year + 1],
            ),
        ];
    }

    /** ZIP roku w pliku tymczasowym; wywołujący usuwa go po wysłaniu. */
    public function archive(int $year): string
    {
        $environment = $this->configuration->environment();
        $path = tempnam(sys_get_temp_dir(), 'kokpit-year-');
        if ($path === false) {
            throw new \RuntimeException('Nie można utworzyć pliku tymczasowego.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Nie można utworzyć archiwum ZIP.');
        }
        $checklist = $this->checklist($year);
        $zip->addFromString('obowiazki-roczne.csv', CsvExport::build(['type','rule_key','period_start','due_date','status','done_at','filing_type','filing_status'], $checklist['obligations']));
        $zip->addFromString('miesiace.csv', CsvExport::build(['month','status','closed_at','correction_required_at'], $checklist['months']));
        $zip->addFromString('zlozenia.csv', CsvExport::build(['type','period_start','period_end','purpose','seq','status','submitted_via','reference_number','accepted_at','xml_files','upo_files'], $checklist['filings']));
        $zip->addFromString('przychody-miesiecznie.csv', CsvExport::build(['month','rate_pct','amount_pln'], $checklist['revenue']));
        $zip->addFromString(sprintf('ewidencja-%d.csv', $year), CsvExport::build(self::LEDGER_COLUMNS, $this->db->iterateAssociative('SELECT ' . implode(', ', self::LEDGER_COLUMNS) . ' FROM revenue_entry WHERE environment=? AND year=? ORDER BY lp', [$environment, $year])));
        $files = $this->db->fetchAllAssociative(
            "SELECT s.id, s.kind, s.sha256, s.media_type, f.type, f.period_start, f.purpose, f.seq FROM stored_file s JOIN filing f ON f.id=s.owner_id
             WHERE s.owner_table='filing' AND s.environment=? AND f.environment=? AND s.kind IN ('xml_sent','upo') AND s.deleted_at IS NULL AND extract(year FROM f.period_start)=?
             ORDER BY f.type, f.period_start, f.purpose, f.seq, s.kind, s.created_at, s.id",
            [$environment, $environment, $year],
        );
        foreach ($files as $file) {
            $bytes = $this->files->read(self::text($file['id']));
            // Archiwum dowodowe: plik musi mieć sha256 z rejestru, inaczej ZIP nie powstaje.
            if (!hash_equals(self::text($file['sha256']), hash('sha256', $bytes))) {
                $zip->close();
                unlink($path);
                throw new \RuntimeException('Plik złożenia ma inny sha256 niż w rejestrze; uruchom app:files:verify.');
            }
            $name = sprintf('zlozenia/%s-%s-%s-%s/%s-%s.%s', self::text($file['type']), self::text($file['period_start']), self::text($file['purpose']), self::text($file['seq']), self::text($file['kind']), substr(self::text($file['sha256']), 0, 12), self::EXTENSIONS[self::text($file['media_type'])] ?? 'bin');
            $zip->addFromString($name, $bytes);
        }
        if (!$zip->close()) {
            throw new \RuntimeException('Nie można zapisać archiwum ZIP.');
        }
        return $path;
    }

    /** @return list<array<string, mixed>> */
    private function obligations(string $environment, int $year): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT o.id, o.type, o.rule_key, o.period_start, o.due_date, o.status, o.done_at, f.type AS filing_type, f.status AS filing_status
             FROM obligation o LEFT JOIN filing f ON f.id=o.filing_id
             WHERE o.environment=? AND o.rule_key IN (?) AND o.nominal_due_date BETWEEN make_date(?::int, 1, 1) AND make_date(?::int, 12, 31)
             ORDER BY o.due_date, o.type, o.id',
            [$environment, self::annualRules(), $year + 1, $year + 1],
            [1 => ArrayParameterType::STRING],
        );
    }

    /** @return list<array<string, mixed>> */
    private function filings(string $environment, int $year): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT f.id, f.type, f.period_start, f.period_end, f.purpose, f.seq, f.status, f.submitted_via, f.reference_number, f.accepted_at,
                (SELECT count(*) FROM stored_file s WHERE s.owner_table='filing' AND s.owner_id=f.id AND s.kind='xml_sent' AND s.deleted_at IS NULL) AS xml_files,
                (SELECT count(*) FROM stored_file s WHERE s.owner_table='filing' AND s.owner_id=f.id AND s.kind='upo' AND s.deleted_at IS NULL) AS upo_files
             FROM filing f WHERE f.environment=? AND extract(year FROM f.period_start)=? ORDER BY f.type, f.period_start, f.purpose, f.seq",
            [$environment, $year],
        );
    }

    /** @return list<array<string, mixed>> */
    private function revenue(string $environment, int $year): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT to_char(revenue_date, 'YYYY-MM') AS month, rate_pct, sum(amount_pln) AS amount_pln FROM revenue_entry
             WHERE environment=? AND year=? GROUP BY 1, rate_pct ORDER BY 1, rate_pct",
            [$environment, $year],
        );
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
