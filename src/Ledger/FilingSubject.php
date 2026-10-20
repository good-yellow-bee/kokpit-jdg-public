<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Calendar\IsoDate;
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
use App\Xml\Pit28Builder;
use App\Xml\VatUeBuilder;
use App\Xml\VatUeDocument;
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
 * pozycji `DI` w `summary`, a przyjęcie zamyka okres przez `MonthClose`. F5.5 dodaje VAT-UE(5) i VAT-UEK(5) za miesiąc
 * (D-66): payload z `VatUeBuilder` bez `DaneAutoryzujace`, twarda bramka K_12 (`VatUeK12Gate`), konektor `edeklaracje`
 * z celem `EDEKLARACJE_TARGET`; przyjęcie oznacza `vat_ue` jako `done`, a VAT-UEK zastępuje poprzednie złożenie okresu.
 * D-96 dodaje PIT-28 za rok przez ten sam konektor: payload z `Pit28Builder` (wariant z XSD w `resources/xsd`), bramki
 * JPK_EWP roku, przyjęty JPK_EWP z tą samą sumą przychodu i na celu `prod` wpis `pit_28.review` dla wariantu.
 *
 * @phpstan-import-type Position from VatUeDocument
 */
final readonly class FilingSubject implements ApprovalSubject
{
    /** Stan złożenia po `approval.status`; `cancelled`, `expired` i `superseded` zależą od kontekstu. */
    private const STATES = ['pending' => 'ready', 'approved' => 'approved', 'dispatching' => 'approved', 'submitted' => 'submitted', 'accepted' => 'accepted', 'rejected' => 'rejected', 'unknown' => 'unknown'];
    /** Konektor wysyłki per typ złożenia. */
    private const CONNECTORS = ['JPK_EWP' => 'jpk', 'JPK_ST' => 'jpk', 'JPK_V7M' => 'jpk', 'VAT_UE' => 'edeklaracje', 'VAT_UEK' => 'edeklaracje', 'PIT_28' => 'edeklaracje'];
    private const VAT_UE_TYPES = ['VAT_UE', 'VAT_UEK'];
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
        private VatUeBuilder $vatUe,
        private VatUeK12Gate $vatUeGate,
        private Pit28Builder $pit28,
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
        $this->assertTarget($environment, self::CONNECTORS[$type]);
        // VAT-UE w `approved` przebudowuje się po KOR (tabela „KOR do faktury `eu_b2b`”); bramka zastępuje zatwierdzenie.
        $vatUe = in_array($type, self::VAT_UE_TYPES, true);
        if (!in_array($filing['status'], $vatUe ? ['draft', 'ready', 'approved'] : ['draft', 'ready'], true)) {
            throw new JpkBuildRefused(sprintf('Złożenie w stanie %s; prośba tylko ze szkicu albo gotowego złożenia.', self::string($filing, 'status')));
        }
        if ($vatUe) {
            return $this->vatUeFiling($subjectId, $filing, $environment);
        }
        if ($type === 'JPK_V7M') {
            return $this->jpkV7m($subjectId, $filing, $environment);
        }
        if ($type === 'PIT_28') {
            return $this->pit28Filing($subjectId, $filing, $environment);
        }
        $year = IsoDate::year(self::string($filing, 'period_start'));
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

    /**
     * VAT-UE(5) albo VAT-UEK(5) za miesiąc (D-66): VAT-UE tylko bez przyjętego VAT-UE okresu; VAT-UEK z BYŁO
     * z `filing.totals.positions` złożenia zastępowanego (`supersedes_id`, przyjęte). Budowa, XSD, bramka K_12.
     *
     * @param array<string,mixed> $filing
     */
    private function vatUeFiling(string $subjectId, array $filing, string $environment): ApprovalPayload
    {
        $type = self::string($filing, 'type');
        $periodStart = self::string($filing, 'period_start');
        $previous = null;
        if ($type === 'VAT_UE') {
            if ($this->db->fetchOne("SELECT 1 FROM filing WHERE environment=? AND type IN ('VAT_UE','VAT_UEK') AND period_start=? AND status='accepted' AND id<>?", [$environment, $periodStart, $subjectId]) !== false) {
                throw new JpkBuildRefused(sprintf('VAT-UE za %s jest przyjęty; zmiana to VAT-UEK.', substr($periodStart, 0, 7)));
            }
            $document = $this->vatUe->build($periodStart);
        } else {
            $previous = $this->db->fetchAssociative("SELECT id, type, status, totals FROM filing WHERE id=? AND type IN ('VAT_UE','VAT_UEK')", [$filing['supersedes_id']]);
            if ($previous === false || $previous['status'] !== 'accepted') {
                throw new JpkBuildRefused(sprintf('VAT-UEK za %s wymaga przyjętego VAT-UE okresu (supersedes_id).', substr($periodStart, 0, 7)));
            }
            $before = self::positions(json_decode(self::string($previous, 'totals'), true, 8, JSON_THROW_ON_ERROR));
            if ($before === null) {
                throw new JpkBuildRefused(sprintf('Złożenie zastępowane za %s nie ma pozycji w filing.totals (np. złożenie manual); VAT-UEK złożysz w e-Deklaracjach.', substr($periodStart, 0, 7)));
            }
            $document = $this->vatUe->buildCorrection($periodStart, $before);
        }
        if (!$document->valid()) {
            throw new JpkBuildRefused(sprintf('%s niezgodny z XSD: %s', $type, implode('; ', array_slice($document->xsdErrors, 0, 3))));
        }
        $gate = $this->vatUeGate->check($environment, $document);
        $totals = $document->totals() + ['k12' => $gate['k12'], 'v7m_filing_id' => $gate['v7m_filing_id'], 'difference' => $gate['difference'], 'rounding_difference' => $gate['rounding_difference'], 'tolerance' => $gate['tolerance']];
        $schemaVersion = $type === 'VAT_UE' ? VatUeBuilder::SCHEMA_VERSION_UE : VatUeBuilder::SCHEMA_VERSION_UEK;
        $summary = ['type' => $type, 'period' => substr($periodStart, 0, 7), 'period_start' => $periodStart, 'period_end' => self::string($filing, 'period_end'), 'purpose' => (int) self::string($filing, 'purpose'), 'schema_version' => $schemaVersion]
            + $totals + ['checklist' => ['k12' => ['ok' => true, 'count' => 0]]];
        unset($summary['xml_sha256']);
        if ($previous !== null) {
            $summary['previous'] = ['filing_id' => self::string($previous, 'id'), 'type' => self::string($previous, 'type')];
        }
        $preconditions = ['xsd' => 'ok (LIBXML_NONET)', 'connector_target' => $this->configuration->target('edeklaracje'), 'environment' => $environment, 'k12_gate' => 'ok', 'auth_data' => 'DaneAutoryzujace przy wysyłce, poza xml_sent'];
        $this->db->update('filing', ['schema_version' => $schemaVersion, 'totals' => json_encode($totals, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'updated_at' => $this->now()], ['id' => $subjectId]);
        return new ApprovalPayload($document->xml, 'application/xml', (int) self::string($filing, 'version'), $summary, $preconditions);
    }

    /**
     * PIT-28 za rok przychodu (D-96): tylko pierwsze złożenie, wariant z XSD, bramki „przed budową JPK_EWP(4)”, przyjęty
     * JPK_EWP roku z sumą K_8 równą przychodowi zeznania, na celu `prod` wpis `pit_28.review` dla wariantu; budowa i XSD.
     *
     * @param array<string,mixed> $filing
     */
    private function pit28Filing(string $subjectId, array $filing, string $environment): ApprovalPayload
    {
        $year = IsoDate::year(self::string($filing, 'period_start'));
        $purpose = (int) self::string($filing, 'purpose');
        if ($purpose !== 1) {
            throw new JpkBuildRefused(sprintf('Korektę PIT-28 za %d składasz ręcznie i zapisujesz jako złożenie manual (D-96).', $year));
        }
        if ($this->db->fetchOne("SELECT 1 FROM filing WHERE environment=? AND type='PIT_28' AND period_start=? AND status='accepted' AND id<>?", [$environment, self::string($filing, 'period_start'), $subjectId]) !== false) {
            throw new JpkBuildRefused(sprintf('PIT-28 za %d jest przyjęty; korektę składasz ręcznie (D-96).', $year));
        }
        $variant = $this->pit28->variant($year) ?? throw new JpkBuildRefused(Pit28Builder::variantMissing($year));
        $gates = $this->gates($environment, $year);
        $problems = array_keys(array_filter($gates, static fn(array $gate): bool => !$gate['ok']));
        if ($problems !== []) {
            throw new JpkBuildRefused('Bramki przed budową PIT-28 niespełnione: ' . implode(', ', array_map(static fn(string $gate): string => sprintf('%s (%d)', $gate, $gates[$gate]['count']), $problems)) . '.');
        }
        $inputs = $this->pit28->inputs($year);
        $ewp = $this->db->fetchAssociative("SELECT id, totals FROM filing WHERE environment=? AND type='JPK_EWP' AND period_start=? AND status='accepted' ORDER BY seq DESC LIMIT 1", [$environment, sprintf('%04d-01-01', $year)]);
        if ($ewp === false) {
            throw new JpkBuildRefused(sprintf('PIT-28 za %d wymaga przyjętego JPK_EWP za ten rok: obie liczby pochodzą z tej samej ewidencji (D-96).', $year));
        }
        $ewpTotals = json_decode(self::string($ewp, 'totals'), true, 8, JSON_THROW_ON_ERROR);
        $ewpSum = is_array($ewpTotals) && is_string($ewpTotals['sum'] ?? null) ? $ewpTotals['sum'] : null;
        if ($ewpSum === null || !BigDecimal::of($ewpSum)->isEqualTo($inputs['revenue_total'])) {
            throw new JpkBuildRefused(sprintf('Przychód PIT-28 %s różny od sumy K_8 przyjętego JPK_EWP %s.', $inputs['revenue_total'], $ewpSum ?? 'brak'));
        }
        $review = null;
        if ($environment === 'prod') {
            $review = $this->settings->value(SettingKeys::PIT_28_REVIEW, $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m-d'));
            if (!is_array($review) || ($review['variant'] ?? null) !== $variant['variant']) {
                throw new JpkBuildRefused(sprintf('Brak wpisu pit_28.review dla %s: przy EDEKLARACJE_TARGET=prod prośba czeka na przegląd doradcy (D-96, D-04).', $variant['variant']));
            }
        }
        $document = $this->pit28->build($year);
        if (!$document->valid()) {
            throw new JpkBuildRefused(sprintf('%s niezgodny z XSD: %s', $document->variant, implode('; ', array_slice($document->xsdErrors, 0, 3))));
        }
        $totals = $document->totals + ['jpk_ewp_filing_id' => self::string($ewp, 'id'), 'xml_sha256' => $document->sha256()];
        $summary = ['type' => 'PIT_28', 'period' => (string) $year, 'period_start' => self::string($filing, 'period_start'), 'period_end' => self::string($filing, 'period_end'), 'purpose' => 1, 'schema_version' => $document->variant]
            + $document->totals + ['checklist' => $gates + ['jpk_ewp_sum' => ['ok' => true, 'count' => 0]]];
        $preconditions = ['xsd' => 'ok (LIBXML_NONET)', 'connector_target' => $this->configuration->target('edeklaracje'), 'environment' => $environment, 'jpk_ewp_sum' => 'ok',
            'adviser_review' => is_array($review) && is_string($review['reviewed_on'] ?? null) ? $review['reviewed_on'] : 'cel testowy', 'auth_data' => 'DaneAutoryzujace przy wysyłce, poza xml_sent'];
        $this->db->update('filing', ['schema_version' => $document->variant, 'totals' => json_encode($totals, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'updated_at' => $this->now()], ['id' => $subjectId]);
        return new ApprovalPayload($document->xml, 'application/xml', (int) self::string($filing, 'version'), $summary, $preconditions);
    }

    /**
     * Pozycje VAT-UE z `filing.totals`; NULL, gdy złożenie ich nie ma.
     *
     * @return list<Position>|null
     */
    private static function positions(mixed $totals): ?array
    {
        $list = is_array($totals) ? ($totals['positions'] ?? null) : null;
        if (!is_array($list)) {
            return null;
        }
        $positions = [];
        foreach ($list as $item) {
            if (!is_array($item) || !is_string($item['country'] ?? null) || !is_string($item['vat_number'] ?? null) || !is_string($item['net_pln'] ?? null) || !is_int($item['p_uc'] ?? null) || !is_int($item['invoices'] ?? null)) {
                return null;
            }
            $positions[] = ['country' => $item['country'], 'vat_number' => $item['vat_number'], 'net_pln' => $item['net_pln'], 'p_uc' => $item['p_uc'], 'invoices' => $item['invoices']];
        }
        return $positions;
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
        $filing = $this->db->fetchAssociative('SELECT environment, type FROM filing WHERE id=?', [$subjectId]);
        if ($filing === false) {
            $this->fallback($subjectId)->checkDecision($subjectId);
            return;
        }
        $environment = self::string($filing, 'environment');
        $this->assertTarget($environment, self::CONNECTORS[self::string($filing, 'type')] ?? 'jpk');
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
        // Przyjęty VAT-UE albo VAT-UEK (z bramki albo `manual` z UPO): `vat_ue` za M `done`, VAT-UEK zastępuje poprzednie.
        if ($state === 'accepted' && in_array($current['type'], self::VAT_UE_TYPES, true) && $current['status'] !== 'accepted') {
            $decided = $this->db->fetchOne("SELECT decided_by FROM approval WHERE subject_type='filing' AND subject_id=? ORDER BY requested_at DESC, id DESC LIMIT 1", [$subjectId]);
            $this->monthClose->vatUeAccepted($subjectId, is_string($decided) ? $decided : null);
        }
        // Przyjęty PIT-28 z bramki (D-96): obowiązek `pit_28` roku `done`.
        if ($state === 'accepted' && $current['type'] === 'PIT_28' && $current['status'] !== 'accepted') {
            $decided = $this->db->fetchOne("SELECT decided_by FROM approval WHERE subject_type='filing' AND subject_id=? ORDER BY requested_at DESC, id DESC LIMIT 1", [$subjectId]);
            $this->monthClose->pit28Accepted($subjectId, is_string($decided) ? $decided : null);
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
        $approval = $this->db->fetchAssociative("SELECT connector, result_code, coalesce(external_refs->>'reference_number', external_refs->>'ref_id') AS reference, external_refs->>'original_reference_number' AS original FROM approval WHERE subject_type='filing' AND subject_id=? ORDER BY requested_at DESC, id DESC LIMIT 1", [$subjectId]);
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

    private function assertTarget(string $environment, string $connector = 'jpk'): void
    {
        if (!$this->configuration->enabled) {
            throw new JpkBuildRefused('Konektory wyłączone (CONNECTORS_ENABLED=0); prośba o wysyłkę złożenia czeka.');
        }
        if ($environment !== $this->configuration->target($connector)) {
            throw new JpkBuildRefused(sprintf('Złożenie ma environment %s, a %s_TARGET to %s.', $environment, strtoupper($connector), $this->configuration->target($connector)));
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
