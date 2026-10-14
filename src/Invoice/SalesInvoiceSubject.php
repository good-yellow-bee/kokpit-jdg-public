<?php

declare(strict_types=1);

namespace App\Invoice;

use App\Approval\ApprovalPayload;
use App\Approval\ApprovalSubject;
use App\Config\ConnectorConfiguration;
use App\Fx\FxRate;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use App\Time\BusinessDate;
use App\Xml\Fa3Builder;
use App\Xml\Fa3BuildRefused;
use App\Xml\Fa3Correction;
use App\Xml\Fa3Invoice;
use App\Xml\Fa3InvoiceLine;
use App\Xml\Fa3Scenario;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Przedmiot bramki `sales_invoice` albo `correction_invoice` (D-41, docs/approval-gate.md): payload to FA(3) z F3.1
 * zbudowany z wierszy `invoice`, `invoice_line` i `counterparty` (DTO niezależny od źródła). Warunki z kolumną „prośba”
 * sprawdza `payload`, z kolumną „decyzja” `checkDecision`. Stan faktury według „Powiązania z bramką zatwierdzeń”
 * w docs/data-model.md; decyzja blokuje P_2 (`invoice_number_locked_at`), przyjęcie zamyka obowiązek `invoice_issue`.
 * Klasa niczego nie wysyła: zapis do KSeF robi wyłącznie handler `official_writes` (F3.3).
 */
final readonly class SalesInvoiceSubject implements ApprovalSubject
{
    /** Stan faktury po `approval.status`; `cancelled` zależy od `cancel_reason`. */
    private const STATES = ['pending' => 'ready','approved' => 'approved','dispatching' => 'approved','submitted' => 'submitted','accepted' => 'accepted','rejected' => 'rejected','unknown' => 'unknown','expired' => 'ready','superseded' => 'ready'];
    private const TIMESTAMP = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        private Connection $db,
        private Fa3Builder $builder,
        private SettingStore $settings,
        private ConnectorConfiguration $configuration,
        private BusinessDate $dates,
        private ClockInterface $clock,
        private string $subjectType,
        private string $plClientMaxAge,
    ) {
        if (!in_array($subjectType, ['sales_invoice','correction_invoice'], true)) {
            throw new \InvalidArgumentException('SalesInvoiceSubject obsługuje sales_invoice i correction_invoice.');
        }
    }

    public function subjectType(): string
    {
        return $this->subjectType;
    }

    public function connector(): string
    {
        return 'ksef';
    }

    public function payload(string $subjectId): ApprovalPayload
    {
        $invoice = $this->invoice($subjectId, true);
        $party = $this->party(self::string($invoice, 'counterparty_id'));
        $verifications = $this->verifications(self::string($invoice, 'counterparty_id'));
        $today = $this->dates->today();
        $scenario = SalesConditions::scenario($party, $verifications);
        $problems = $this->requestProblems($invoice, $scenario);
        $verificationId = null;
        if ($scenario !== null) {
            [$buyerProblem, $verificationId] = SalesConditions::buyerProblem($scenario, $verifications, $today, new \DateInterval($this->plClientMaxAge));
            if ($buyerProblem !== null) {
                $problems[] = $buyerProblem;
            }
        }
        if ($problems !== [] || $scenario === null) {
            throw new Fa3BuildRefused($problems);
        }
        $lines = $this->lines($subjectId);
        $buyer = self::buyer($party);
        $fa3 = Fa3Scenario::from($scenario);
        $vatCode = SalesConditions::vatCode($scenario, $this->settings->value(SettingKeys::VAT_STANDARD_RATE, self::text($invoice, 'service_date') ?? self::text($invoice, 'service_period_end') ?? $today))
            ?? throw new Fa3BuildRefused(['Brak vat.standard_rate w ustawieniach.']);
        $dto = new Fa3Invoice(
            self::string($invoice, 'kind'),
            self::string($invoice, 'invoice_number'),
            $fa3,
            self::string($invoice, 'currency'),
            $buyer,
            array_map(static fn(array $line): Fa3InvoiceLine => new Fa3InvoiceLine($line['line_no'], $line['description'], $line['unit'], $line['quantity'], $line['unit_net_price'], $line['net_amount'], $vatCode, null, $line['pkwiu'], $line['state_before']), $lines),
            self::text($invoice, 'service_date'),
            self::text($invoice, 'service_period_start'),
            self::text($invoice, 'service_period_end'),
            $this->correction($invoice),
        );
        $payload = $this->builder->build($dto);
        $p1 = SalesConditions::p1Problem($payload->issueDate, $today);
        if ($p1 !== null) {
            throw new Fa3BuildRefused([$p1]);
        }
        $snapshot = self::snapshot($buyer);
        $now = $this->clock->now()->format(self::TIMESTAMP);
        $this->db->update('invoice', [
            'issue_date' => $payload->issueDate,'schema_version' => $payload->schemaVersion,'tax_point_date' => $payload->taxPointDate,'vat_period_start' => substr($payload->taxPointDate, 0, 8) . '01',
            'net_total' => $payload->netTotal,'vat_total' => $payload->vatTotal,'gross_total' => $payload->grossTotal,'fx_vat_rate_id' => $payload->fxVatRate?->id,
            'net_pln' => $payload->netPln,'vat_pln' => $payload->vatPln,'tax_scenario' => $scenario,'buyer_snapshot' => $snapshot,'verification_id' => $verificationId,'updated_at' => $now,
        ], ['id' => $subjectId]);
        $this->db->executeStatement('UPDATE invoice_line SET vat_code=?, updated_at=? WHERE invoice_id=?', [$vatCode, $now, $subjectId]);
        $verification = $verificationId === null ? null : $this->db->fetchAssociative('SELECT result, checked_for_date, source FROM verification WHERE id=?', [$verificationId]);
        $summary = [
            'kind' => self::string($invoice, 'kind'),'scenario' => $scenario,'p12' => $vatCode,'p1' => $payload->issueDate,'p2' => self::string($invoice, 'invoice_number'),
            'p6' => self::text($invoice, 'service_date'),'service_period' => self::text($invoice, 'service_period_start') === null ? null : [self::text($invoice, 'service_period_start'), self::text($invoice, 'service_period_end')],
            'buyer' => json_decode($snapshot, true, 8, JSON_THROW_ON_ERROR),
            'verification' => $verification === false || $verification === null ? null : ['source' => $verification['source'],'result' => $verification['result'],'checked_for_date' => $verification['checked_for_date']],
            'lines' => array_map(static fn(array $line): array => ['no' => $line['line_no'],'description' => $line['description'],'quantity' => $line['quantity'],'unit_net_price' => $line['unit_net_price'],'net' => $line['net_amount'],'p12' => $vatCode,'state_before' => $line['state_before']], $lines),
            'currency' => self::string($invoice, 'currency'),'net' => $payload->netTotal,'vat' => $payload->vatTotal,'gross' => $payload->grossTotal,'net_pln' => $payload->netPln,'vat_pln' => $payload->vatPln,
            'fx' => self::rate($payload->fxVatRate),'tax_point_date' => $payload->taxPointDate,'schema_version' => $payload->schemaVersion,
            'correction' => self::string($invoice, 'kind') === 'KOR' ? ['original_number' => self::text($invoice, 'corrected_invoice_number'),'original_ksef_number' => self::text($invoice, 'corrected_ksef_number'),'reason' => self::text($invoice, 'correction_reason')] : null,
        ];
        $preconditions = ['xsd' => 'ok (LIBXML_NONET, Fa3PresendCheck)','connector_target' => $this->configuration->target('ksef'),'environment' => self::string($invoice, 'environment'),
            'buyer_id' => $verificationId === null ? null : 'ok','p1_not_future' => true,'p2_collision' => false,'tax_scenario' => $scenario,'ryczalt_rates' => 'ok'];
        return new ApprovalPayload($payload->xml, 'application/xml', self::int($invoice, 'version'), $summary, $preconditions);
    }

    public function checkDecision(string $subjectId): void
    {
        $invoice = $this->invoice($subjectId, false);
        $today = $this->dates->today();
        $target = $this->configuration->target('ksef');
        $problems = [];
        if (self::string($invoice, 'environment') !== $target) {
            $problems[] = sprintf('Faktura ma environment %s, a KSEF_TARGET to %s.', self::string($invoice, 'environment'), $target);
        }
        $party = $this->party(self::string($invoice, 'counterparty_id'));
        $verifications = $this->verifications(self::string($invoice, 'counterparty_id'));
        $scenario = SalesConditions::scenario($party, $verifications);
        if ($scenario === null || $scenario !== self::text($invoice, 'tax_scenario')) {
            $problems[] = 'Scenariusz nabywcy zmienił się od prośby; zatwierdzasz nową prośbę („Odnów”).';
        } else {
            [$buyerProblem] = SalesConditions::buyerProblem($scenario, $verifications, $today, new \DateInterval($this->plClientMaxAge));
            if ($buyerProblem !== null) {
                $problems[] = $buyerProblem;
            }
            $review = SalesConditions::fa3ReviewProblem($target, $this->settings->value(SettingKeys::KSEF_FA3_REVIEW, $today), $scenario, self::string($invoice, 'kind'));
            if ($review !== null) {
                $problems[] = $review;
            }
        }
        $p1 = SalesConditions::p1Problem(self::string($invoice, 'issue_date'), $today);
        if ($p1 !== null) {
            $problems[] = $p1;
        }
        $collision = $this->collision($invoice);
        if ($collision !== null) {
            $problems[] = $collision;
        }
        if (!$this->writeCredential($target)) {
            $problems[] = 'Brak ważnego poświadczenia zapisu KSeF (ksef_token_write albo ksef_auth_cert) dla celu ' . $target . '.';
        }
        if ($problems !== []) {
            throw new \DomainException(implode('; ', $problems));
        }
    }

    public function currentVersion(string $subjectId): int
    {
        return self::int($this->invoice($subjectId, false), 'version');
    }

    public function onStatus(string $subjectId, string $status, ?string $cancelReason): void
    {
        $state = $status === 'cancelled' ? match ($cancelReason) {
            'user', 'manual_invoice' => 'cancelled_before_send',
            'hash_mismatch' => 'ready',
            default => null,
        } : self::STATES[$status] ?? null;
        if ($state === null) {
            return;
        }
        $now = $this->clock->now()->format(self::TIMESTAMP);
        $fields = ['status' => $state,'updated_at' => $now];
        if ($status === 'approved') {
            // Blokada P_2 przy decyzji (docs/modules/freshbooks.md, „Znacznik gotowości i blokada P_2”).
            $fields['invoice_number_locked_at'] = $now;
        } elseif (in_array($state, ['ready','cancelled_before_send'], true)) {
            $fields['invoice_number_locked_at'] = null;
        }
        $this->db->update('invoice', $fields, ['id' => $subjectId]);
        if ($state === 'accepted') {
            // Obowiązek `invoice_issue` szkicu zamyka przyjęcie w KSeF (docs/compliance-calendar.md).
            $this->db->executeStatement("UPDATE obligation SET status='done', done_at=?, updated_at=? WHERE invoice_id=? AND rule_key='invoice_issue.draft' AND status='open'", [$now, $now, $subjectId]);
        }
    }

    /**
     * Warunki z kolumną „prośba” poza identyfikatorem nabywcy.
     *
     * @param array<string,mixed> $invoice
     * @return list<string>
     */
    private function requestProblems(array $invoice, ?string $scenario): array
    {
        $problems = [];
        $target = $this->configuration->target('ksef');
        if (self::string($invoice, 'environment') !== $target) {
            $problems[] = sprintf('Faktura ma environment %s, a KSEF_TARGET to %s; prośba nie powstaje.', self::string($invoice, 'environment'), $target);
        }
        if (!$this->configuration->enabled) {
            $problems[] = 'Konektory wyłączone (CONNECTORS_ENABLED=0); prośba nie powstaje.';
        }
        $kind = self::string($invoice, 'kind');
        if (self::string($invoice, 'direction') !== 'sale' || $kind !== ($this->subjectType === 'sales_invoice' ? 'VAT' : 'KOR')) {
            $problems[] = sprintf('Przedmiot %s nie obsługuje faktury rodzaju %s.', $this->subjectType, $kind);
        }
        if ($kind === 'VAT' && self::text($invoice, 'service_date') === null && self::text($invoice, 'service_period_end') === null) {
            $problems[] = 'Brak daty albo okresu usługi; wpisujesz je w panelu przed ready.';
        }
        if ($scenario === null) {
            $problems[] = 'Kontrahent bez identyfikatora podatkowego: tylko B2B (D-05).';
        }
        $id = self::string($invoice, 'id');
        $lines = $this->db->fetchAllAssociative('SELECT line_no, ryczalt_rate_pct FROM invoice_line WHERE invoice_id=? AND NOT state_before ORDER BY line_no', [$id]);
        if ($lines === []) {
            $problems[] = 'Faktura bez pozycji.';
        }
        foreach ($lines as $line) {
            if ($line['ryczalt_rate_pct'] === null) {
                $problems[] = sprintf('Pozycja %s bez stawki ryczałtu z ryczalt.service_map.', is_scalar($line['line_no']) ? (string) $line['line_no'] : '?');
            }
        }
        $collision = $this->collision($invoice);
        if ($collision !== null) {
            $problems[] = $collision;
        }
        if ($kind === 'KOR') {
            $problems = [...$problems, ...$this->originalProblems($invoice)];
        }
        return $problems;
    }

    /** @param array<string,mixed> $invoice */
    private function collision(array $invoice): ?string
    {
        $taken = $this->db->fetchOne(
            "SELECT 1 FROM invoice WHERE environment=? AND direction='sale' AND kind=? AND invoice_number=? AND id<>? AND ksef_number IS NOT NULL",
            [self::string($invoice, 'environment'), self::string($invoice, 'kind'), self::string($invoice, 'invoice_number'), self::string($invoice, 'id')],
        );
        return $taken === false ? null : 'P_2 koliduje z numerem faktury już przyjętej w KSeF; zmień numer we FreshBooks (docs/modules/ksef.md, „Kolizje P_2”).';
    }

    /**
     * KOR: faktura korygowana przyjęta z numerem KSeF albo oznaczona `NrKSeFN` (docs/approval-gate.md).
     *
     * @param array<string,mixed> $invoice
     * @return list<string>
     */
    private function originalProblems(array $invoice): array
    {
        $originalId = self::text($invoice, 'corrects_invoice_id');
        if ($originalId !== null) {
            $original = $this->db->fetchAssociative('SELECT status, ksef_number FROM invoice WHERE id=?', [$originalId]);
            if ($original === false || !in_array($original['status'], ['accepted','corrected'], true) || !is_string($original['ksef_number'])) {
                return ['Faktura korygowana nie jest przyjęta z numerem KSeF.'];
            }
            return [];
        }
        if (self::text($invoice, 'corrected_ksef_number') === null && (self::text($invoice, 'corrected_invoice_number') === null || self::text($invoice, 'corrected_issue_date') === null)) {
            return ['KOR bez faktury korygowanej z numerem KSeF i bez oznaczenia NrKSeFN.'];
        }
        return [];
    }

    /** @param array<string,mixed> $invoice */
    private function correction(array $invoice): ?Fa3Correction
    {
        if (self::string($invoice, 'kind') !== 'KOR') {
            return null;
        }
        $rate = null;
        $originalId = self::text($invoice, 'corrects_invoice_id');
        if ($originalId !== null && self::string($invoice, 'currency') !== 'PLN') {
            $row = $this->db->fetchAssociative('SELECT r.id, r.currency, r.table_no, r.effective_date, r.mid FROM invoice i JOIN fx_rate r ON r.id=i.fx_vat_rate_id WHERE i.id=?', [$originalId]);
            if ($row !== false) {
                $rate = new FxRate(self::string($row, 'id'), self::string($row, 'currency'), self::string($row, 'table_no'), self::string($row, 'effective_date'), self::string($row, 'mid'));
            }
        }
        $type = $invoice['correction_type'] ?? null;
        return new Fa3Correction(
            self::text($invoice, 'corrected_invoice_number') ?? throw new Fa3BuildRefused(['KOR bez numeru faktury korygowanej.']),
            self::text($invoice, 'corrected_issue_date') ?? throw new Fa3BuildRefused(['KOR bez daty faktury korygowanej.']),
            self::text($invoice, 'corrected_ksef_number'),
            self::text($invoice, 'correction_reason'),
            is_int($type) ? $type : null,
            $rate,
        );
    }

    private function writeCredential(string $target): bool
    {
        $valid = $this->db->fetchOne(
            "SELECT 1 FROM credential_state WHERE environment=? AND kind IN ('ksef_token_write','ksef_auth_cert') AND status IN ('valid','expiring') AND revoked_at IS NULL AND (valid_until IS NULL OR valid_until > ?)",
            [$target, $this->clock->now()->format(self::TIMESTAMP)],
        );
        return $valid !== false;
    }

    /** @return array<string,mixed> */
    private function invoice(string $id, bool $lock): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM invoice WHERE id=?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
        if ($row === false) {
            throw new \DomainException('Brak faktury.');
        }
        return $row;
    }

    /** @return array{country:string,nip:?string,eu_vat_prefix:?string,eu_vat_number:?string,foreign_tax_id:?string,name:string,address:array{country:string,line1:?string,line2:?string}} */
    private function party(string $counterpartyId): array
    {
        $row = $this->db->fetchAssociative('SELECT name, country, nip, eu_vat_prefix, eu_vat_number, foreign_tax_id, address FROM counterparty WHERE id=?', [$counterpartyId]);
        if ($row === false) {
            throw new \DomainException('Brak kontrahenta faktury.');
        }
        $address = json_decode(self::text($row, 'address') ?? '{}', true, 4, JSON_THROW_ON_ERROR);
        $address = is_array($address) ? $address : [];
        $line = static function (string $key) use ($address): ?string {
            $value = $address[$key] ?? null;
            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        };
        $country = self::string($row, 'country');
        return [
            'country' => $country,'nip' => self::text($row, 'nip'),'eu_vat_prefix' => self::text($row, 'eu_vat_prefix'),'eu_vat_number' => self::text($row, 'eu_vat_number'),
            'foreign_tax_id' => self::text($row, 'foreign_tax_id'),'name' => self::string($row, 'name'),
            'address' => ['country' => $line('country') ?? $country,'line1' => $line('line1'),'line2' => $line('line2')],
        ];
    }

    /** @return list<array{id:string,source:?string,result:string,checked_for_date:string}> od najnowszej */
    private function verifications(string $counterpartyId): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT id, source, result, checked_for_date FROM verification WHERE counterparty_id=? ORDER BY checked_at DESC, id DESC', [$counterpartyId]);
        return array_map(static fn(array $row): array => ['id' => self::string($row, 'id'),'source' => self::text($row, 'source'),'result' => self::string($row, 'result'),'checked_for_date' => self::string($row, 'checked_for_date')], $rows);
    }

    /** @return list<array{line_no:int,description:string,unit:string,quantity:string,unit_net_price:string,net_amount:string,pkwiu:?string,state_before:bool}> */
    private function lines(string $invoiceId): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT line_no, description, unit, quantity, unit_net_price, net_amount, pkwiu, state_before FROM invoice_line WHERE invoice_id=? ORDER BY line_no, state_before DESC', [$invoiceId]);
        return array_map(static fn(array $row): array => [
            'line_no' => self::int($row, 'line_no'),'description' => self::string($row, 'description'),'unit' => self::string($row, 'unit'),'quantity' => self::string($row, 'quantity'),
            'unit_net_price' => self::text($row, 'unit_net_price') ?? self::string($row, 'net_amount'),'net_amount' => self::string($row, 'net_amount'),'pkwiu' => self::text($row, 'pkwiu'),'state_before' => $row['state_before'] === true,
        ], $rows);
    }

    /** @param array{country:string,nip:?string,eu_vat_prefix:?string,eu_vat_number:?string,foreign_tax_id:?string,name:string,address:array{country:string,line1:?string,line2:?string}} $party */
    private static function buyer(array $party): Fa3Party
    {
        if ($party['nip'] !== null) {
            return new Fa3Party($party['nip'], null, null, null, null, false, $party['name'], $party['address']);
        }
        if ($party['eu_vat_prefix'] !== null && $party['eu_vat_number'] !== null && $party['eu_vat_prefix'] !== 'XI') {
            return new Fa3Party(null, $party['eu_vat_prefix'], $party['eu_vat_number'], null, null, false, $party['name'], $party['address']);
        }
        $id = $party['foreign_tax_id'] ?? $party['eu_vat_number'];
        return new Fa3Party(null, null, null, $party['eu_vat_prefix'] ?? $party['country'], $id, $id === null, $party['name'], $party['address']);
    }

    /** `buyer_snapshot` w kształcie `Fa3Party::fromSnapshot`. */
    private static function snapshot(Fa3Party $buyer): string
    {
        $data = array_filter(['name' => $buyer->name,'nip' => $buyer->nip,'eu_vat_prefix' => $buyer->euVatPrefix,'eu_vat_number' => $buyer->euVatNumber,'id_country' => $buyer->idCountry,'foreign_tax_id' => $buyer->foreignTaxId], static fn(?string $value): bool => $value !== null);
        if ($buyer->noId) {
            $data['no_id'] = true;
        }
        return json_encode($data + ['address' => array_filter($buyer->address, static fn(?string $value): bool => $value !== null)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @return array{rate:string,table:string,date:string}|null */
    private static function rate(?FxRate $rate): ?array
    {
        return $rate === null ? null : ['rate' => $rate->mid,'table' => $rate->tableNo,'date' => $rate->effectiveDate];
    }

    /** @param array<string,mixed> $row */
    private static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        return is_string($value) ? $value : throw new \LogicException(sprintf('Niepoprawne pole %s.', $key));
    }

    /** @param array<string,mixed> $row */
    private static function text(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string,mixed> $row */
    private static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        return is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : throw new \LogicException(sprintf('Niepoprawne pole %s.', $key)));
    }
}
