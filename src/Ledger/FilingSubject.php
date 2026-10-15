<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Approval\ApprovalPayload;
use App\Approval\ApprovalSubject;
use App\Config\ConnectorConfiguration;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use App\Xml\JpkBuildRefused;
use App\Xml\JpkDocument;
use App\Xml\JpkEwpBuilder;
use App\Xml\JpkStBuilder;
use App\Xml\JpkV7mBuilder;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Przedmiot bramki `filing` (docs/approval-gate.md, „Przedmioty zatwierdzeń”; usługa `filing.subject`). F5.1 obsługuje
 * JPK_EWP(4): payload to dokument z generatora F2.4 zbudowany raz przy prośbie, po bramkach „przed budową JPK_EWP(4)”
 * z docs/compliance-calendar.md i uzgodnieniu K_8 z `revenue_entry`. F5.2 dodaje JPK_ST(1) tą samą ścieżką: payload
 * z `JpkStBuilder`, jedyna bramka to `jpk_st.enabled` na ostatni dzień roku (Q-25, D-64). Stan złożenia według „Powiązania z bramką
 * zatwierdzeń” w docs/data-model.md. W dev i test identyfikator bez wiersza `filing` obsługuje atrapa `dummy`
 * (`$fallback`, D-32); przy `APP_ENV=prod` atrapy nie ma. F5.4 dodaje JPK_V7M(3) za miesiąc (D-65): bramki kompletności
 * `V7mGates` przy prośbie (wynik w `period.checklist`, niespełniona bramka blokuje), payload z `JpkV7mBuilder`, lista
 * pozycji `DI` w `summary`, a przyjęcie zamyka okres przez `MonthClose`.
 */
final readonly class FilingSubject implements ApprovalSubject
{
    /** Stan złożenia po `approval.status`; `cancelled`, `expired` i `superseded` zależą od kontekstu. */
    private const STATES = ['pending' => 'ready', 'approved' => 'approved', 'dispatching' => 'approved', 'submitted' => 'submitted', 'accepted' => 'accepted', 'rejected' => 'rejected', 'unknown' => 'unknown'];
    /** Konektor wysyłki per typ złożenia; VAT-UE (F5.5) dochodzi w swojej części. */
    private const CONNECTORS = ['JPK_EWP' => 'jpk', 'JPK_ST' => 'jpk', 'JPK_V7M' => 'jpk'];
    private const FINAL_SALES = "('accepted','corrected','superseded','cancelled_before_send')";
    private const TIMESTAMP = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        private Connection $db,
        private JpkEwpBuilder $ewp,
        private JpkStBuilder $st,
        private JpkV7mBuilder $v7m,
        private V7mGates $v7mGates,
        private MonthClose $monthClose,
        private Periods $periods,
        private SettingStore $settings,
        private ConnectorConfiguration $configuration,
        private ClockInterface $clock,
        private ?ApprovalSubject $fallback = null,
    ) {}

    public function subjectType(): string
    {
        return 'filing';
    }

    public function connector(string $subjectId): ?string
    {
        $type = $this->db->fetchOne('SELECT type FROM filing WHERE id=?', [$subjectId]);
        if (!is_string($type)) {
            return $this->fallback($subjectId)->connector($subjectId);
        }
        return self::CONNECTORS[$type] ?? throw new JpkBuildRefused(sprintf('Wysyłka %s przez bramkę powstaje w kolejnej części F5.', $type));
    }

    public function payload(string $subjectId): ApprovalPayload
    {
        $filing = $this->db->fetchAssociative('SELECT * FROM filing WHERE id=? FOR UPDATE', [$subjectId]);
        if ($filing === false) {
            return $this->fallback($subjectId)->payload($subjectId);
        }
        $type = self::string($filing, 'type');
        if (!isset(self::CONNECTORS[$type])) {
            throw new JpkBuildRefused(sprintf('Wysyłka %s przez bramkę powstaje w kolejnej części F5.', $type));
        }
        $environment = self::string($filing, 'environment');
        $this->assertTarget($environment);
        if (!in_array($filing['status'], ['draft', 'ready'], true)) {
            throw new JpkBuildRefused(sprintf('Złożenie w stanie %s; prośba tylko ze szkicu albo gotowego złożenia.', self::string($filing, 'status')));
        }
        if ($type === 'JPK_V7M') {
            return $this->jpkV7m($subjectId, $filing, $environment);
        }
        $year = (int) substr(self::string($filing, 'period_start'), 0, 4);
        $purpose = (int) self::string($filing, 'purpose');
        [$document, $summary, $preconditions] = $type === 'JPK_ST' ? $this->jpkSt($environment, $year, $purpose) : $this->jpkEwp($environment, $year, $purpose);
        $summary = ['type' => $type, 'period' => (string) $year, 'period_start' => self::string($filing, 'period_start'), 'period_end' => self::string($filing, 'period_end'), 'purpose' => $purpose, 'schema_version' => $document->schemaVersion] + $summary;
        $preconditions = ['xsd' => 'ok (LIBXML_NONET)', 'connector_target' => $this->configuration->target('jpk'), 'environment' => $environment] + $preconditions;
        $totals = ['rows' => $document->rows, 'sum' => $document->sum, 'sum_by_rate' => $document->sumsByRate, 'xml_sha256' => $document->sha256()] + array_intersect_key($summary, ['revenue_entry_sum' => true]);
        $this->db->update('filing', ['schema_version' => $document->schemaVersion, 'totals' => json_encode($totals, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => $this->now()], ['id' => $subjectId]);
        return new ApprovalPayload($document->xml, 'application/xml', (int) self::string($filing, 'version'), $summary, $preconditions);
    }

    /**
     * JPK_V7M(3) za miesiąc: stan okresu (pierwsze złożenie tylko w okresie niezamkniętym, korekta tylko po ponownym
     * otwarciu), bramki `V7mGates`, budowa i XSD; `period.checklist` z listą `DI`, `filing.totals` i `summary` (D-65).
     *
     * @param array<string,mixed> $filing
     */
    private function jpkV7m(string $subjectId, array $filing, string $environment): ApprovalPayload
    {
        $periodStart = self::string($filing, 'period_start');
        $purpose = (int) self::string($filing, 'purpose');
        $status = $this->periods->status($periodStart);
        if ($purpose === 1 && ($status === 'closed' || $this->acceptedV7m($environment, $periodStart, $subjectId))) {
            throw new JpkBuildRefused(sprintf('Okres %s ma przyjęty JPK_V7M albo jest zamknięty; zmiana to korekta po ponownym otwarciu.', substr($periodStart, 0, 7)));
        }
        if ($purpose === 2 && $status !== 'reopened') {
            throw new JpkBuildRefused(sprintf('Korekta JPK_V7M za %s tylko po ponownym otwarciu okresu ze step-up.', substr($periodStart, 0, 7)));
        }
        $gates = $this->v7mGates->evaluate($periodStart);
        if (!$gates['ok']) {
            throw new JpkBuildRefused('Bramki kompletności przed budową JPK_V7M niespełnione: ' . V7mGates::failures($gates) . '.');
        }
        $previousTotals = json_decode(self::string($filing, 'totals'), true, 8, JSON_THROW_ON_ERROR);
        $reason = is_array($previousTotals) && is_string($previousTotals['p_ordzu'] ?? null) ? $previousTotals['p_ordzu'] : null;
        $document = $this->v7m->build($periodStart, $purpose, $reason);
        if (!$document->valid()) {
            throw new JpkBuildRefused('JPK_V7M(3) niezgodny z XSD: ' . implode('; ', array_slice($document->xsdErrors, 0, 3)));
        }
        $this->periods->saveChecklist($periodStart, $gates + ['di_items' => $document->diItems]);
        $summary = ['type' => 'JPK_V7M', 'period' => substr($periodStart, 0, 7), 'period_start' => $periodStart, 'period_end' => self::string($filing, 'period_end'), 'purpose' => $purpose, 'schema_version' => JpkV7mBuilder::SCHEMA_VERSION]
            + $document->totals + ['declaration' => $document->declaration, 'di_items' => $document->diItems, 'checklist' => $gates['gates']];
        if ($filing['supersedes_id'] !== null) {
            $previous = $this->db->fetchOne('SELECT totals FROM filing WHERE id=?', [$filing['supersedes_id']]);
            $previous = is_string($previous) ? json_decode($previous, true, 8, JSON_THROW_ON_ERROR) : null;
            $summary['previous'] = ['filing_id' => self::string($filing, 'supersedes_id'), 'p51' => is_array($previous) ? ($previous['p51'] ?? null) : null, 'p62' => is_array($previous) ? ($previous['p62'] ?? null) : null];
        }
        $preconditions = ['xsd' => 'ok (LIBXML_NONET)', 'connector_target' => $this->configuration->target('jpk'), 'environment' => $environment, 'gates' => 'ok', 'p39_source' => $document->p39Source];
        $this->db->update('filing', ['schema_version' => JpkV7mBuilder::SCHEMA_VERSION, 'totals' => json_encode($document->totals, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'updated_at' => $this->now()], ['id' => $subjectId]);
        return new ApprovalPayload($document->xml, 'application/xml', (int) self::string($filing, 'version'), $summary, $preconditions);
    }

    private function acceptedV7m(string $environment, string $periodStart, string $except): bool
    {
        return $this->db->fetchOne("SELECT 1 FROM filing WHERE environment=? AND type='JPK_V7M' AND period_start=? AND status='accepted' AND id<>?", [$environment, $periodStart, $except]) !== false;
    }

    /**
     * JPK_EWP(4): bramki „przed budową JPK_EWP(4)” i suma K_8 równa sumie `revenue_entry` roku.
     *
     * @return array{JpkDocument,array<string,mixed>,array<string,mixed>} dokument, `summary` i `preconditions` typu
     */
    private function jpkEwp(string $environment, int $year, int $purpose): array
    {
        $gates = $this->gates($environment, $year);
        $problems = array_keys(array_filter($gates, static fn(array $gate): bool => !$gate['ok']));
        if ($problems !== []) {
            throw new JpkBuildRefused('Bramki przed budową JPK_EWP(4) niespełnione: ' . implode(', ', array_map(static fn(string $gate): string => sprintf('%s (%d)', $gate, $gates[$gate]['count']), $problems)) . '.');
        }
        $document = $this->ewp->build($year, null, $purpose);
        if (!$document->valid()) {
            throw new JpkBuildRefused('JPK_EWP(4) niezgodny z XSD: ' . implode('; ', array_slice($document->xsdErrors, 0, 3)));
        }
        $ledger = $this->db->fetchOne('SELECT coalesce(sum(amount_pln),0) FROM revenue_entry WHERE environment=? AND year=?', [$environment, $year]);
        $ledgerSum = BigDecimal::of(is_scalar($ledger) ? (string) $ledger : '0')->toScale(2);
        if (!$ledgerSum->isEqualTo($document->sum)) {
            throw new JpkBuildRefused(sprintf('Suma K_8 %s różna od sumy revenue_entry %s.', $document->sum, (string) $ledgerSum));
        }
        $summary = ['rows' => $document->rows, 'sum_k8' => $document->sum, 'sum_by_rate' => $document->sumsByRate, 'revenue_entry_sum' => (string) $ledgerSum, 'checklist' => $gates];
        $preconditions = ['ledger_closed' => $gates['ledger_closed']['ok'], 'pit_fx_rates' => $gates['pit_fx_rates']['ok'], 'sales_final' => $gates['sales_final']['ok'], 'k8_equals_ledger' => true];
        return [$document, $summary, $preconditions];
    }

    /**
     * JPK_ST(1): tylko przy `jpk_st.enabled` = true na ostatni dzień roku, jak warunek obowiązku `jpk_st` (D-39, Q-25);
     * wykaz bez wierszy jest zgodny z XSD i przy włączonej fladze przechodzi (D-64).
     *
     * @return array{JpkDocument,array<string,mixed>,array<string,mixed>} dokument, `summary` i `preconditions` typu
     */
    private function jpkSt(string $environment, int $year, int $purpose): array
    {
        if (!self::stEnabled($this->settings, $year)) {
            throw new JpkBuildRefused(self::stDisabled($year));
        }
        $document = $this->st->build($year, null, $purpose);
        if (!$document->valid()) {
            throw new JpkBuildRefused('JPK_ST(1) niezgodny z XSD: ' . implode('; ', array_slice($document->xsdErrors, 0, 3)));
        }
        $assets = $this->count('SELECT count(*) FROM fixed_asset WHERE environment=?', [$environment]);
        $summary = ['rows' => $document->rows, 'sum_f10' => $document->sum, 'fixed_asset_count' => $assets, 'checklist' => ['jpk_st_enabled' => ['ok' => true, 'count' => 0]]];
        return [$document, $summary, ['jpk_st_enabled' => true]];
    }

    /** `jpk_st.enabled` na 31.12. roku złożenia, jak warunek obowiązku `jpk_st` w `ObligationEngine`. */
    public static function stEnabled(SettingStore $settings, int $year): bool
    {
        return $settings->value(SettingKeys::JPK_ST_ENABLED, sprintf('%04d-12-31', $year)) === true;
    }

    /** Komunikat Q-25 przy wyłączonej fladze; prośba nie powstaje. */
    public static function stDisabled(int $year): string
    {
        return sprintf('JPK_ST za %d wyłączony: jpk_st.enabled = false na 31.12.%d. Brak środków trwałych do rozstrzygnięcia „plik bez wierszy czy brak pliku” (Q-25); prośba o zatwierdzenie nie powstaje.', $year, $year);
    }

    public function checkDecision(string $subjectId): void
    {
        $filing = $this->db->fetchAssociative('SELECT environment FROM filing WHERE id=?', [$subjectId]);
        if ($filing === false) {
            $this->fallback($subjectId)->checkDecision($subjectId);
            return;
        }
        $environment = self::string($filing, 'environment');
        $this->assertTarget($environment);
        $credential = $this->db->fetchOne(
            "SELECT 1 FROM credential_state WHERE environment=? AND kind='auth_data' AND status IN ('valid','expiring') AND revoked_at IS NULL AND (valid_until IS NULL OR valid_until > ?)",
            [$environment, $this->now()],
        );
        if ($credential === false) {
            throw new \DomainException('Brak ważnego poświadczenia auth_data dla celu JPK.');
        }
    }

    public function currentVersion(string $subjectId): int
    {
        $version = $this->db->fetchOne('SELECT version FROM filing WHERE id=?', [$subjectId]);
        if (!is_numeric($version)) {
            return $this->fallback($subjectId)->currentVersion($subjectId);
        }
        return (int) $version;
    }

    public function onStatus(string $subjectId, string $status, ?string $cancelReason): void
    {
        $current = $this->db->fetchAssociative('SELECT type, status FROM filing WHERE id=?', [$subjectId]);
        if ($current === false) {
            $this->fallback?->onStatus($subjectId, $status, $cancelReason);
            return;
        }
        $fields = [];
        $state = match (true) {
            $status === 'cancelled' => match ($cancelReason) {
                'manual_filing' => 'accepted',
                'user', 'hash_mismatch' => 'draft',
                default => null,
            },
            // Nowa prośba ustawia `ready` po swoim wpisie; bez żywego zatwierdzenia złożenie wraca do szkicu.
            in_array($status, ['expired', 'superseded'], true) => $this->live($subjectId) ? 'ready' : 'draft',
            default => self::STATES[$status] ?? null,
        };
        if ($state === null) {
            return;
        }
        if ($status === 'cancelled' && $cancelReason === 'manual_filing') {
            $fields = ['submitted_via' => 'manual', 'accepted_at' => $this->now()];
        } elseif (in_array($status, ['submitted', 'accepted', 'rejected', 'unknown'], true)) {
            $fields = $this->result($subjectId, $status);
        }
        $this->db->update('filing', ['status' => $state, 'updated_at' => $this->now()] + $fields, ['id' => $subjectId]);
        // Przyjęty JPK_V7M (z bramki albo `manual` z UPO) zamyka okres raz (D-49, D-65).
        if ($state === 'accepted' && $current['type'] === 'JPK_V7M' && $current['status'] !== 'accepted') {
            $decided = $this->db->fetchOne("SELECT decided_by FROM approval WHERE subject_type='filing' AND subject_id=? ORDER BY requested_at DESC, id DESC LIMIT 1", [$subjectId]);
            $this->monthClose->accepted($subjectId, is_string($decided) ? $decided : null);
        }
    }

    /**
     * Bramki przed budową JPK_EWP(4) (docs/compliance-calendar.md): 12 okresów z `ledger_closed_at`, kurs PIT każdej
     * sprzedaży w walucie, faktury sprzedaży roku w stanach końcowych.
     *
     * @return array<string,array{ok:bool,count:int}>
     */
    private function gates(string $environment, int $year): array
    {
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-01-01', $year + 1);
        $closed = $this->count('SELECT count(*) FROM period WHERE environment=? AND period_start>=? AND period_start<? AND ledger_closed_at IS NOT NULL', [$environment, $from, $to]);
        $fx = $this->count("SELECT count(*) FROM invoice WHERE environment=? AND direction='sale' AND currency<>'PLN' AND fx_pit_rate_id IS NULL AND status NOT IN ('cancelled_before_send','rejected') AND coalesce(revenue_date, issue_date)>=? AND coalesce(revenue_date, issue_date)<?", [$environment, $from, $to]);
        $sales = $this->count("SELECT count(*) FROM invoice WHERE environment=? AND direction='sale' AND status NOT IN " . self::FINAL_SALES . ' AND issue_date>=? AND issue_date<?', [$environment, $from, $to]);
        return [
            'ledger_closed' => ['ok' => $closed === 12, 'count' => 12 - $closed],
            'pit_fx_rates' => ['ok' => $fx === 0, 'count' => $fx],
            'sales_final' => ['ok' => $sales === 0, 'count' => $sales],
        ];
    }

    /** @return array<string,scalar|null> numer referencyjny, kod statusu, kanał i przyjęcie z ostatniego zatwierdzenia */
    private function result(string $subjectId, string $status): array
    {
        $approval = $this->db->fetchAssociative("SELECT connector, result_code, external_refs->>'reference_number' AS reference, external_refs->>'original_reference_number' AS original FROM approval WHERE subject_type='filing' AND subject_id=? ORDER BY requested_at DESC, id DESC LIMIT 1", [$subjectId]);
        if ($approval === false) {
            return [];
        }
        $fields = [];
        $reference = is_string($approval['reference']) ? $approval['reference'] : (is_string($approval['original']) ? $approval['original'] : null);
        if ($reference !== null) {
            $fields['reference_number'] = $reference;
        }
        if (is_string($approval['result_code']) && ctype_digit($approval['result_code'])) {
            $fields['status_code'] = (int) $approval['result_code'];
        }
        if (in_array($status, ['submitted', 'accepted'], true) && is_string($approval['connector'])) {
            $fields['submitted_via'] = $approval['connector'];
        }
        if ($status === 'accepted') {
            $fields['accepted_at'] = $this->now();
        }
        return $fields;
    }

    private function assertTarget(string $environment): void
    {
        if (!$this->configuration->enabled) {
            throw new JpkBuildRefused('Konektory wyłączone (CONNECTORS_ENABLED=0); prośba o wysyłkę złożenia czeka.');
        }
        if ($environment !== $this->configuration->target('jpk')) {
            throw new JpkBuildRefused(sprintf('Złożenie ma environment %s, a JPK_TARGET to %s.', $environment, $this->configuration->target('jpk')));
        }
    }

    /** Atrapa `dummy` dla identyfikatora bez wiersza `filing` (tylko dev i test); bez niej brak złożenia to błąd. */
    private function fallback(string $subjectId): ApprovalSubject
    {
        return $this->fallback ?? throw new \DomainException(sprintf('Brak złożenia %s.', $subjectId));
    }

    private function live(string $subjectId): bool
    {
        return $this->db->fetchOne("SELECT 1 FROM approval WHERE subject_type='filing' AND subject_id=? AND status IN ('pending','approved','dispatching','submitted','unknown')", [$subjectId]) !== false;
    }

    /** @param list<mixed> $parameters */
    private function count(string $sql, array $parameters): int
    {
        $count = $this->db->fetchOne($sql, $parameters);
        return is_numeric($count) ? (int) $count : throw new \LogicException('Niepoprawny wynik bramki.');
    }

    /** @param array<string,mixed> $row */
    private static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        return is_scalar($value) ? (string) $value : throw new \LogicException(sprintf('Niepoprawne pole filing.%s.', $key));
    }

    private function now(): string
    {
        return $this->clock->now()->format(self::TIMESTAMP);
    }
}
