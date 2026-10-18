<?php

declare(strict_types=1);

namespace App\Ops;

use App\Audit\AuditLog;
use App\Storage\FileStore;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Sprawdzenia testu odtworzenia (docs/operations.md, „Test odtworzenia”, D-89): hashe plików, łańcuch `audit_log` z kotwicami
 * snapshotu odtworzonego i najstarszego, liczby `approval`, `filing` i `revenue_entry` z miesiąca porównane z PROD.
 * Raport ma tylko liczby, hashe i identyfikatory snapshotów, bez danych kontrahentów.
 */
final readonly class RestoreCheck
{
    public const KIND = 'kokpit.restore_check';
    public const COUNTS_KIND = 'kokpit.restore_counts';
    /** Tabela → kolumna chwili, po której liczy się miesiąc w Europe/Warsaw. */
    public const COUNTED = ['approval' => 'requested_at', 'filing' => 'created_at', 'revenue_entry' => 'recorded_at'];

    public function __construct(private Connection $db, private FileStore $files, private AuditLog $audit, private ClockInterface $clock) {}

    /** Poprzedni miesiąc kalendarzowy w Europe/Warsaw (`RRRR-MM`). */
    public function previousMonth(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Warsaw'))->modify('first day of previous month')->format('Y-m');
    }

    /** @return array<string,int> */
    public function counts(string $month): array
    {
        $start = self::monthStart($month);
        $counts = [];
        foreach (self::COUNTED as $table => $column) {
            $value = $this->db->fetchOne(sprintf('SELECT count(*) FROM %s WHERE %s >= ? AND %s < ?', $table, $column, $column), [$start->format('c'), $start->modify('first day of next month')->format('c')]);
            $counts[$table] = is_numeric($value) ? (int) $value : throw new \UnexpectedValueException('Niepoprawna liczba wierszy.');
        }
        return $counts;
    }

    /** @return array{kind:string,month:string,counts:array<string,int>} */
    public function countsDocument(string $month): array
    {
        return ['kind' => self::COUNTS_KIND, 'month' => $month, 'counts' => $this->counts($month)];
    }

    /**
     * Snapshot odtworzony i najstarszy z `restic snapshots --json`, z kotwicami z tagów `audit-head:` (D-79).
     *
     * @return array{restored:array{id:string,time:string,anchor:?string},oldest:array{id:string,time:string,anchor:?string}}
     */
    public static function snapshots(string $json, string $restoredId): array
    {
        $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !array_is_list($data) || $data === []) {
            throw new \InvalidArgumentException('Lista snapshotów restic jest pusta albo niepoprawna.');
        }
        $snapshots = [];
        foreach ($data as $snapshot) {
            if (!is_array($snapshot) || !is_string($snapshot['id'] ?? null) || preg_match('/^[0-9a-f]{64}$/D', $snapshot['id']) !== 1 || !is_string($snapshot['time'] ?? null)) {
                throw new \InvalidArgumentException('Niepoprawny snapshot restic.');
            }
            // restic podaje nanosekundy; PHP czyta najwyżej mikrosekundy.
            $time = new \DateTimeImmutable((string) preg_replace('/(\.\d{6})\d+/', '$1', $snapshot['time']));
            $anchor = null;
            foreach (is_array($snapshot['tags'] ?? null) ? $snapshot['tags'] : [] as $tag) {
                if (is_string($tag) && preg_match('/^audit-head:\d{1,18}:[0-9a-f]{64}$/D', $tag) === 1) {
                    $anchor = $tag;
                }
            }
            $snapshots[] = ['id' => $snapshot['id'], 'time' => $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), 'anchor' => $anchor, 'at' => $time];
        }
        usort($snapshots, static fn(array $a, array $b): int => $a['at'] <=> $b['at']);
        $restored = array_values(array_filter($snapshots, static fn(array $snapshot): bool => $snapshot['id'] === $restoredId));
        if ($restored === []) {
            throw new \InvalidArgumentException('Odtworzonego snapshotu nie ma na liście restic.');
        }
        $strip = static fn(array $snapshot): array => ['id' => $snapshot['id'], 'time' => $snapshot['time'], 'anchor' => $snapshot['anchor']];
        return ['restored' => $strip($restored[0]), 'oldest' => $strip($snapshots[0])];
    }

    /**
     * Pełny raport testu odtworzenia; `passed` tylko przy zgodnych plikach, łańcuchu, obu kotwicach i liczbach.
     *
     * @param array{restored:array{id:string,time:string,anchor:?string},oldest:array{id:string,time:string,anchor:?string}} $snapshots
     * @param array{month:string,counts:array<string,int>}|null $expected
     * @return array{kind:string,checked_at:string,commit:string,schema_version:?string,snapshot:array{id:string,time:string,anchor:?string},oldest_snapshot:array{id:string,time:string,anchor:?string},files:array{checked:int,failed:int},audit:array{chain_valid:bool,head:string,anchors:list<array{snapshot:string,anchor:?string,matches:bool}>},counts:array{month:string,restored:array<string,int>,expected:?array<string,int>,matches:?bool},passed:bool}
     */
    public function report(array $snapshots, ?array $expected, ?string $month = null): array
    {
        $month ??= $expected['month'] ?? $this->previousMonth();
        if ($expected !== null && $expected['month'] !== $month) {
            throw new \InvalidArgumentException('Miesiąc liczb z PROD różni się od --month.');
        }
        $chainValid = $this->audit->verify();
        $anchors = [];
        foreach (['restored', 'oldest'] as $which) {
            $anchor = $snapshots[$which]['anchor'];
            $anchors[] = ['snapshot' => $which, 'anchor' => $anchor, 'matches' => $anchor !== null && $this->audit->matchesAnchor($anchor)];
        }
        $head = $this->audit->head();
        $files = $this->files->verify();
        $restored = $this->counts($month);
        $countsMatch = $expected === null ? null : $expected['counts'] === $restored;
        $schema = $this->db->fetchOne('SELECT max(version) FROM doctrine_migration_versions');
        $passed = $chainValid && $files['failed'] === [] && !in_array(false, array_column($anchors, 'matches'), true) && $countsMatch !== false;
        return [
            'kind' => self::KIND,
            'checked_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'commit' => (string) (getenv('KOKPIT_COMMIT') ?: 'unknown'),
            'schema_version' => is_string($schema) ? $schema : null,
            'snapshot' => $snapshots['restored'],
            'oldest_snapshot' => $snapshots['oldest'],
            'files' => ['checked' => $files['checked'], 'failed' => count($files['failed'])],
            'audit' => ['chain_valid' => $chainValid, 'head' => 'audit-head:' . $head, 'anchors' => $anchors],
            'counts' => ['month' => $month, 'restored' => $restored, 'expected' => $expected['counts'] ?? null, 'matches' => $countsMatch],
            'passed' => $passed,
        ];
    }

    /**
     * Liczby z PROD z `app:ops:restore-check --counts-only`.
     *
     * @return array{month:string,counts:array<string,int>}
     */
    public static function parseCounts(string $json): array
    {
        $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['kind'] ?? null) !== self::COUNTS_KIND || !is_string($data['month'] ?? null) || !is_array($data['counts'] ?? null)) {
            throw new \InvalidArgumentException('Plik liczb nie pochodzi z app:ops:restore-check --counts-only.');
        }
        self::monthStart($data['month']);
        $counts = [];
        foreach (array_keys(self::COUNTED) as $table) {
            $value = $data['counts'][$table] ?? null;
            $counts[$table] = is_int($value) && $value >= 0 ? $value : throw new \InvalidArgumentException(sprintf('Brak liczby %s w pliku liczb.', $table));
        }
        return ['month' => $data['month'], 'counts' => $counts];
    }

    /**
     * Szczegóły wpisu `ops.restored` z raportu testu odtworzenia, sprawdzone pole po polu.
     *
     * @return array{passed:bool,checked_at:string,commit:string,snapshot_id:string,snapshot_time:string,files_checked:int,files_failed:int,chain_valid:bool,audit_head:string,anchors:array<string,bool>,counts_month:string,counts_match:?bool}
     */
    public static function restoredEntry(string $json): array
    {
        $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        $snapshot = is_array($data) ? ($data['snapshot'] ?? null) : null;
        $files = is_array($data) ? ($data['files'] ?? null) : null;
        $audit = is_array($data) ? ($data['audit'] ?? null) : null;
        $counts = is_array($data) ? ($data['counts'] ?? null) : null;
        $countsMatch = is_array($counts) ? ($counts['matches'] ?? null) : null;
        if (!is_array($data) || ($data['kind'] ?? null) !== self::KIND || !is_bool($data['passed'] ?? null) || !is_string($data['checked_at'] ?? null)
            || !is_array($snapshot) || !is_string($snapshot['id'] ?? null) || preg_match('/^[0-9a-f]{64}$/D', $snapshot['id']) !== 1 || !is_string($snapshot['time'] ?? null)
            || !is_array($files) || !is_int($files['checked'] ?? null) || !is_int($files['failed'] ?? null)
            || !is_array($audit) || !is_bool($audit['chain_valid'] ?? null) || !is_string($audit['head'] ?? null) || !is_array($audit['anchors'] ?? null)
            || !is_array($counts) || !is_string($counts['month'] ?? null) || !(is_bool($countsMatch) || $countsMatch === null)) {
            throw new \InvalidArgumentException('Plik nie jest raportem app:ops:restore-check.');
        }
        $anchors = [];
        foreach ($audit['anchors'] as $anchor) {
            if (!is_array($anchor) || !is_string($anchor['snapshot'] ?? null) || !is_bool($anchor['matches'] ?? null)) {
                throw new \InvalidArgumentException('Niepoprawna kotwica w raporcie.');
            }
            $anchors[$anchor['snapshot']] = $anchor['matches'];
        }
        return [
            'passed' => $data['passed'],
            'checked_at' => $data['checked_at'],
            'commit' => is_string($data['commit'] ?? null) ? $data['commit'] : 'unknown',
            'snapshot_id' => $snapshot['id'],
            'snapshot_time' => $snapshot['time'],
            'files_checked' => $files['checked'],
            'files_failed' => $files['failed'],
            'chain_valid' => $audit['chain_valid'],
            'audit_head' => $audit['head'],
            'anchors' => $anchors,
            'counts_month' => $counts['month'],
            'counts_match' => $countsMatch,
        ];
    }

    private static function monthStart(string $month): \DateTimeImmutable
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/D', $month) !== 1) {
            throw new \InvalidArgumentException('Miesiąc w formacie RRRR-MM.');
        }
        return new \DateTimeImmutable($month . '-01T00:00:00', new \DateTimeZone('Europe/Warsaw'));
    }
}
