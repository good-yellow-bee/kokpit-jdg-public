<?php

declare(strict_types=1);

namespace App\Invoice;

use App\Approval\ApprovalGate;
use App\Approval\ApprovalTransitions;
use App\Audit\AuditLog;
use App\Config\ConnectorConfiguration;
use App\Connector\FreshBooks\FreshBooksInvoice;
use App\Fx\FxRateUnavailable;
use App\Ledger\ServiceMap;
use App\Messenger\Message\ObligationRecomputeMessage;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use App\Time\BusinessDate;
use App\Xml\Fa3BuildRefused;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Znacznik gotowości faktury sprzedaży (docs/modules/freshbooks.md, „Znacznik gotowości i blokada P_2”, D-52). Po każdej
 * klasyfikacji szkicu FreshBooks: pozycje do `invoice_line`, potem `draft` → `ready` i prośba `sales_invoice`, gdy szkic
 * ma znacznik `freshbooks.ready_marker` w `po_number` i spełnia warunki stanu `ready`. Powody pozostania w `draft` trafiają
 * do `invoice.ready_blockers` i panelu. Zdjęcie znacznika przed decyzją: `superseded` i `draft`; po decyzji bez skutku.
 * Zmiana źródła albo numeru szkicu w `ready` i `approved` daje nową prośbę (stara `superseded`). Działa tylko przy
 * włączonym `freshbooks.sales_enabled`. Niczego nie wysyła do KSeF.
 */
final readonly class SalesReadiness
{
    private const TIMESTAMP = 'Y-m-d\TH:i:s.uP';
    /** Jednostka P_8A pozycji z FreshBooks: usługa (D-52). */
    private const UNIT = 'usł.';

    public function __construct(
        private Connection $db,
        private ApprovalGate $gate,
        private ApprovalTransitions $transitions,
        private SettingStore $settings,
        private ConnectorConfiguration $configuration,
        private BusinessDate $dates,
        private ClockInterface $clock,
        private AuditLog $audit,
        private MessageBusInterface $bus,
        private string $readyMarker,
    ) {}

    /** Wywołanie z `FreshBooksSync::pollInvoices` po `FreshBooksLedger::classify`, w tej samej transakcji. */
    public function observe(FreshBooksInvoice $invoice, string $outcome): void
    {
        if (!$this->enabled()) {
            return;
        }
        $id = $this->db->fetchOne("SELECT id FROM invoice WHERE environment=? AND freshbooks_id=? AND direction='sale'", [$this->configuration->environment(), $invoice->id]);
        if (!is_string($id)) {
            return;
        }
        $status = $this->db->fetchOne('SELECT status FROM invoice WHERE id=? FOR UPDATE', [$id]);
        if (in_array($status, ['draft','ready','approved','rejected'], true) && ($outcome !== 'draft_unchanged' || !$this->hasLines($id))) {
            $this->syncLines($id, $invoice);
        }
        $this->evaluate($id, $this->marked($invoice), $outcome);
    }

    /**
     * Stan faktury po znaczniku i wyniku klasyfikacji. `$outcome` `draft_updated` albo `number_conflict` oznacza zmianę
     * źródła szkicu (nowe zatwierdzenie albo powrót do `draft`).
     */
    public function evaluate(string $invoiceId, bool $marked, string $outcome = 'draft_unchanged'): void
    {
        if (!$this->enabled()) {
            return;
        }
        $this->db->transactional(function () use ($invoiceId, $marked, $outcome): void {
            $status = $this->db->fetchOne("SELECT status FROM invoice WHERE id=? AND direction='sale' FOR UPDATE", [$invoiceId]);
            if ($outcome === 'number_conflict' && in_array($status, ['draft','ready','approved'], true)) {
                $this->retreat($invoiceId, ['Nowy numer szkicu z FreshBooks koliduje z inną fakturą sprzedaży (P_2); zmień numer we FreshBooks.']);
                return;
            }
            if ($status === 'draft') {
                if ($marked) {
                    $this->request($invoiceId);
                } else {
                    $this->blockers($invoiceId, null);
                }
            } elseif ($status === 'ready' && !$marked) {
                $this->retreat($invoiceId, null);
            } elseif (in_array($status, ['ready','approved','rejected'], true) && $outcome === 'draft_updated') {
                // Zdjęcie znacznika po zatwierdzeniu nie ma skutku; zmiana źródła przed wysyłką daje nową prośbę. Faktura
                // odrzucona przez KSeF nie została wystawiona: poprawka we FreshBooks daje `ready` i nową prośbę, bez KOR.
                $this->request($invoiceId);
            }
        });
    }

    /**
     * Data albo okres usługi (P_6, okres z P_6 w FA(3)) wpisane w panelu przed `ready`; przelicza obowiązek
     * `invoice_issue` i ponownie ocenia szkic ze znacznikiem (znacznik oznacza niepusty `ready_blockers`).
     */
    public function setService(string $invoiceId, ?string $serviceDate, ?string $periodStart, ?string $periodEnd, ?string $userId): void
    {
        if (($serviceDate === null) === ($periodStart === null || $periodEnd === null) || ($periodStart !== null && $periodEnd !== null && $periodStart > $periodEnd)) {
            throw new \InvalidArgumentException('Podaj datę usługi albo pełny okres usługi.');
        }
        $marked = $this->db->transactional(function () use ($invoiceId, $serviceDate, $periodStart, $periodEnd, $userId): bool {
            $row = $this->db->fetchAssociative("SELECT environment, status, ready_blockers FROM invoice WHERE id=? AND direction='sale' FOR UPDATE", [$invoiceId]);
            if ($row === false || !in_array($row['status'], ['draft','ready'], true)) {
                throw new \DomainException('Datę usługi zmieniasz tylko w szkicu przed decyzją.');
            }
            $this->db->update('invoice', ['service_date' => $serviceDate,'service_period_start' => $periodStart,'service_period_end' => $periodEnd,'updated_at' => $this->now()], ['id' => $invoiceId]);
            $environment = is_string($row['environment']) ? $row['environment'] : null;
            $this->audit->append('invoice.service_set', ['service_date' => $serviceDate,'service_period_start' => $periodStart,'service_period_end' => $periodEnd], $environment, 'invoice', $invoiceId, $userId);
            return $row['ready_blockers'] !== null || $row['status'] === 'ready';
        });
        $this->bus->dispatch(new ObligationRecomputeMessage('obligations.recompute'));
        // W `ready` zmiana daty zmienia payload: nowe zatwierdzenie jak przy zmianie źródła.
        $this->evaluate($invoiceId, $marked, 'draft_updated');
    }

    /**
     * Warunki stanu `ready` niezależne od payloadu (docs/approval-gate.md, „Warunki wstępne”): cel, konektory, data usługi,
     * onboarding klienta, pozycje ze stawką ryczałtu. Pusta lista: można prosić o zatwierdzenie.
     *
     * @return list<string>
     */
    public function problems(string $invoiceId): array
    {
        $invoice = $this->db->fetchAssociative('SELECT environment, kind, invoice_number, counterparty_id, service_date, service_period_end, freshbooks_credit_id, net_total, corrects_invoice_id, corrected_ksef_number, corrected_issue_date FROM invoice WHERE id=?', [$invoiceId]);
        if ($invoice === false) {
            throw new \DomainException('Brak faktury.');
        }
        $problems = [];
        $target = $this->configuration->target('ksef');
        if ($invoice['environment'] !== $target) {
            $problems[] = sprintf('Faktura ma environment %s, a KSEF_TARGET to %s.', is_string($invoice['environment']) ? $invoice['environment'] : '?', $target);
        }
        if (!$this->configuration->enabled) {
            $problems[] = 'Konektory wyłączone (CONNECTORS_ENABLED=0).';
        }
        if ($invoice['kind'] === 'VAT' && $invoice['service_date'] === null && $invoice['service_period_end'] === null) {
            $problems[] = 'Brak daty albo okresu usługi; wpisz je w panelu.';
        }
        $counterparty = is_string($invoice['counterparty_id']) ? $invoice['counterparty_id'] : null;
        if ($counterparty === null || !$this->onboarded($counterparty)) {
            $problems[] = 'Klient bez ukończonego onboardingu: identyfikator podatkowy i weryfikacja (docs/modules/freshbooks.md, „Klient i kontrahent”).';
        }
        $lines = $this->db->fetchAllAssociative('SELECT line_no, ryczalt_rate_pct FROM invoice_line WHERE invoice_id=? AND NOT state_before ORDER BY line_no', [$invoiceId]);
        if ($lines === []) {
            $problems[] = 'Faktura bez pozycji.';
        }
        foreach ($lines as $line) {
            if ($line['ryczalt_rate_pct'] === null) {
                $problems[] = sprintf('Pozycja %s bez stawki w ryczalt.service_map.', is_int($line['line_no']) ? (string) $line['line_no'] : '?');
            }
        }
        if ($invoice['kind'] === 'KOR') {
            $problems = [...$problems, ...$this->correctionProblems($invoiceId, $invoice)];
        }
        return $problems;
    }

    /**
     * Szkic KOR (F3.2 część b, D-53): P_2 to numer credit note; oryginał przyjęty z numerem KSeF albo oznaczony NrKSeFN;
     * przy credit note suma różnic pozycji równa uznaniu (`net_total`).
     *
     * @param array<string,mixed> $invoice
     * @return list<string>
     */
    private function correctionProblems(string $invoiceId, array $invoice): array
    {
        $problems = [];
        if (!is_string($invoice['invoice_number'])) {
            $problems[] = 'KOR bez numeru credit note (P_2); wystaw credit note we FreshBooks z linią „Faktura: <numer>” w notatce.';
        }
        if (is_string($invoice['corrects_invoice_id'])) {
            $original = $this->db->fetchAssociative('SELECT status, ksef_number FROM invoice WHERE id=?', [$invoice['corrects_invoice_id']]);
            if ($original === false || !in_array($original['status'], ['accepted','corrected'], true) || !is_string($original['ksef_number'])) {
                $problems[] = 'Faktura korygowana nie jest przyjęta z numerem KSeF.';
            }
        } elseif (!is_string($invoice['corrected_ksef_number']) && !is_string($invoice['corrected_issue_date'])) {
            $problems[] = 'KOR bez faktury korygowanej z numerem KSeF i bez oznaczenia NrKSeFN.';
        }
        if (is_string($invoice['freshbooks_credit_id']) && is_string($invoice['net_total'])) {
            $difference = $this->db->fetchOne('SELECT coalesce(sum(CASE WHEN state_before THEN -net_amount ELSE net_amount END), 0) FROM invoice_line WHERE invoice_id=?', [$invoiceId]);
            if (!is_string($difference) || !BigDecimal::of($difference)->isEqualTo($invoice['net_total'])) {
                $problems[] = 'Pozycje credit note nie pasują do pozycji faktury korygowanej (nazwa pozycji); popraw credit note we FreshBooks.';
            }
        }
        return $problems;
    }

    /** Szkic ze znacznikiem: `ready` z `ready_at` i prośba w jednej transakcji albo powody w `ready_blockers`. */
    private function request(string $invoiceId): void
    {
        $problems = $this->problems($invoiceId);
        if ($problems === []) {
            $kind = $this->db->fetchOne('SELECT kind FROM invoice WHERE id=?', [$invoiceId]);
            try {
                $this->db->transactional(function () use ($invoiceId, $kind): void {
                    $now = $this->now();
                    $this->db->executeStatement('UPDATE invoice SET ready_at=coalesce(ready_at, ?), ready_blockers=NULL, updated_at=? WHERE id=?', [$now, $now, $invoiceId]);
                    $this->gate->request($kind === 'KOR' ? 'correction_invoice' : 'sales_invoice', $invoiceId);
                });
                return;
            } catch (Fa3BuildRefused $refused) {
                $problems = $refused->problems;
            } catch (\DomainException | Fa3Exception | FxRateUnavailable $failure) {
                $problems = [$failure->getMessage()];
            }
        }
        $status = $this->db->fetchOne('SELECT status FROM invoice WHERE id=?', [$invoiceId]);
        if ($status === 'draft') {
            $this->blockers($invoiceId, $problems);
            return;
        }
        $this->retreat($invoiceId, $problems);
    }

    /**
     * Powrót do `draft`: żywe `pending` albo `approved` → `superseded` bez następcy (D-52), `ready_at` i blokada P_2 znikają.
     *
     * @param list<string>|null $problems
     */
    private function retreat(string $invoiceId, ?array $problems): void
    {
        $live = $this->db->fetchAssociative("SELECT * FROM approval WHERE subject_type IN ('sales_invoice','correction_invoice') AND subject_id=? AND status IN ('pending','approved') FOR UPDATE", [$invoiceId]);
        if ($live !== false) {
            $this->transitions->apply($live, 'superseded', 'supersede', ['finished_at' => $this->transitions->now()], ['reason' => $problems === null ? 'ready_marker_removed' : 'source_changed']);
        }
        $this->db->update('invoice', ['status' => 'draft','ready_at' => null,'invoice_number_locked_at' => null,'updated_at' => $this->now()], ['id' => $invoiceId]);
        $this->blockers($invoiceId, $problems);
    }

    /** @param list<string>|null $problems */
    private function blockers(string $invoiceId, ?array $problems): void
    {
        $this->db->executeStatement('UPDATE invoice SET ready_blockers=?, updated_at=? WHERE id=? AND ready_blockers IS DISTINCT FROM ?::jsonb', [
            $problems === null ? null : json_encode($problems, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $this->now(), $invoiceId,
            $problems === null ? null : json_encode($problems, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** Pozycje szkicu z DTO FreshBooks: P_7 z nazwy, P_8B i P_9A z `qty` i `unit_cost`, stawka z `ryczalt.service_map`. */
    private function syncLines(string $invoiceId, FreshBooksInvoice $invoice): void
    {
        $row = $this->db->fetchAssociative('SELECT counterparty_id, service_date, service_period_end FROM invoice WHERE id=?', [$invoiceId]);
        if ($row === false) {
            return;
        }
        $day = is_string($row['service_date']) ? $row['service_date'] : (is_string($row['service_period_end']) ? $row['service_period_end'] : $this->dates->today());
        $map = ServiceMap::fromSetting($this->settings->value(SettingKeys::RYCZALT_SERVICE_MAP, $day));
        $scenario = is_string($row['counterparty_id']) ? SalesConditions::scenario(...$this->party($row['counterparty_id'])) : null;
        // Kod P_12 tymczasowy; przy prośbie ustala go `SalesInvoiceSubject` ze scenariusza.
        $vatCode = SalesConditions::vatCode($scenario, $this->settings->value(SettingKeys::VAT_STANDARD_RATE, $day)) ?? '';
        $now = $this->now();
        $this->db->executeStatement('DELETE FROM invoice_line WHERE invoice_id=?', [$invoiceId]);
        $lineNo = 0;
        foreach ($invoice->lines as $line) {
            $entry = $map->match($line['name'], $line['description'] ?? $line['name']);
            $this->db->insert('invoice_line', [
                'id' => (string) UuidV7::generate($this->clock->now()),'invoice_id' => $invoiceId,'line_no' => ++$lineNo,'state_before' => 'false',
                'description' => trim($line['name']) !== '' ? $line['name'] : ($line['description'] ?? '-'),'service_type' => $entry['service_type'] ?? null,'pkwiu' => $entry['pkwiu'] ?? null,
                'quantity' => $line['qty'] ?? '1','unit' => self::UNIT,'unit_net_price' => $line['unit_cost'] ?? $line['net'],'net_amount' => $line['net'],'vat_code' => $vatCode,
                'ryczalt_rate_pct' => $entry['rate_pct'] ?? null,'created_at' => $now,'updated_at' => $now,
            ]);
        }
    }

    private function onboarded(string $counterpartyId): bool
    {
        return SalesConditions::onboarded(...$this->party($counterpartyId));
    }

    /**
     * @return array{array{country:string,nip:?string,eu_vat_prefix:?string,eu_vat_number:?string,foreign_tax_id:?string}, list<array{id:string,source:?string,result:string,checked_for_date:string}>}
     */
    private function party(string $counterpartyId): array
    {
        $row = $this->db->fetchAssociative('SELECT country, nip, eu_vat_prefix, eu_vat_number, foreign_tax_id FROM counterparty WHERE id=?', [$counterpartyId]);
        if ($row === false) {
            throw new \DomainException('Brak kontrahenta faktury.');
        }
        $text = static fn(mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;
        $party = ['country' => $text($row['country']) ?? '','nip' => $text($row['nip']),'eu_vat_prefix' => $text($row['eu_vat_prefix']),'eu_vat_number' => $text($row['eu_vat_number']),'foreign_tax_id' => $text($row['foreign_tax_id'])];
        $verifications = [];
        foreach ($this->db->fetchAllAssociative('SELECT id, source, result, checked_for_date FROM verification WHERE counterparty_id=? ORDER BY checked_at DESC, id DESC', [$counterpartyId]) as $verification) {
            $verifications[] = ['id' => $text($verification['id']) ?? '','source' => $text($verification['source']),'result' => $text($verification['result']) ?? '','checked_for_date' => $text($verification['checked_for_date']) ?? ''];
        }
        return [$party, $verifications];
    }

    private function hasLines(string $invoiceId): bool
    {
        return $this->db->fetchOne('SELECT 1 FROM invoice_line WHERE invoice_id=? LIMIT 1', [$invoiceId]) !== false;
    }

    private function marked(FreshBooksInvoice $invoice): bool
    {
        return $invoice->poNumber !== null && str_contains($invoice->poNumber, $this->readyMarker);
    }

    private function enabled(): bool
    {
        return $this->settings->value(SettingKeys::FRESHBOOKS_SALES_ENABLED) === true;
    }

    private function now(): string
    {
        return $this->clock->now()->format(self::TIMESTAMP);
    }
}
