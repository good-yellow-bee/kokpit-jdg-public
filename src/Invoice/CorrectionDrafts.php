<?php

declare(strict_types=1);

namespace App\Invoice;

use App\Audit\AuditLog;
use App\Config\ConnectorConfiguration;
use App\Connector\FreshBooks\FreshBooksCreditNote;
use App\Connector\FreshBooks\FreshBooksInvoice;
use App\Counterparty\CounterpartyDirectory;
use App\Ledger\FreshBooksLedger;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use App\Time\BusinessDate;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Szkic KOR z FreshBooks (docs/modules/ksef.md, „Korekty KOR”, Q-27, D-53). Credit note do faktury przyjętej daje szkic
 * KOR z numerem credit note jako P_2 i pozycjami „przed” i „po” (po = przed minus uznanie). Edycja przyjętej faktury bez
 * credit note daje szkic KOR bez numeru, który czeka w `draft`; credit note do tej faktury nadaje mu numer. Zmiana samego
 * numeru przyjętej faktury daje tylko alert. Szkic ocenia `SalesReadiness` (`correction_invoice`); nic nie trafia do KSeF.
 */
final readonly class CorrectionDrafts
{
    private const TIMESTAMP = 'Y-m-d\TH:i:s.uP';
    private const OPEN = "('draft','ready','approved')";

    public function __construct(
        private Connection $db,
        private SalesReadiness $readiness,
        private SalesTasks $tasks,
        private CounterpartyDirectory $counterparties,
        private SettingStore $settings,
        private ConnectorConfiguration $configuration,
        private BusinessDate $dates,
        private ClockInterface $clock,
        private AuditLog $audit,
    ) {}

    /** Wywołanie z `FreshBooksSync::pollInvoices` po klasyfikacji faktury. */
    public function observe(FreshBooksInvoice $invoice, string $outcome): void
    {
        if (!in_array($outcome, ['number_changed','edited_after_acceptance'], true) || !$this->enabled()) {
            return;
        }
        $id = $this->db->fetchOne("SELECT id FROM invoice WHERE environment=? AND freshbooks_id=? AND direction='sale'", [$this->configuration->environment(), $invoice->id]);
        if (!is_string($id)) {
            return;
        }
        if ($outcome === 'number_changed') {
            $this->numberChanged($id, $invoice->number);
            return;
        }
        $this->fromEdit($id, $invoice);
    }

    /** Zmiana samego P_2 po przyjęciu: numer w KSeF zostaje, przywracasz go we FreshBooks (docs/modules/freshbooks.md). */
    public function numberChanged(string $invoiceId, string $newNumber): void
    {
        $this->tasks->open($invoiceId, 'freshbooks_number_changed', true, $invoiceId . ':' . hash('sha256', $newNumber));
    }

    /** Edycja przyjętej faktury bez credit note: szkic KOR z różnicą, bez numeru (czeka na credit note). */
    public function fromEdit(string $originalId, FreshBooksInvoice $edited): string
    {
        return $this->db->transactional(function () use ($originalId, $edited): string {
            $original = $this->original($originalId);
            $open = $this->openKor($originalId);
            if ($open !== null && $open['freshbooks_credit_id'] !== null) {
                // Credit note ma pierwszeństwo: pozycje szkicu wynikają z uznania.
                return 'kor_unchanged';
            }
            if ($open !== null && $open['status'] !== 'draft') {
                return 'kor_unchanged';
            }
            $after = [];
            foreach ($edited->lines as $index => $line) {
                $after[$index + 1] = ['description' => $line['name'] !== '' ? $line['name'] : ($line['description'] ?? '-'),'quantity' => $line['qty'] ?? '1','unit_net_price' => $line['unit_cost'] ?? $line['net'],'net' => $line['net']];
            }
            $hash = $edited->sourceHash();
            if ($open !== null && $open['source_hash'] === $hash) {
                $this->readiness->evaluate($open['id'], true);
                return 'kor_unchanged';
            }
            $id = $open === null ? $this->insert($original, null, null, $edited->currency) : $open['id'];
            $this->lines($id, $originalId, $after);
            $difference = BigDecimal::of($edited->net)->minus(self::string($original, 'net_total'));
            $this->db->executeStatement('UPDATE invoice SET source_hash=?, net_total=?, version=version+?, updated_at=? WHERE id=?', [$hash, (string) $difference->toScale(2), $open === null ? 0 : 1, $this->now(), $id]);
            $this->audit->append('invoice.kor_drafted', ['source' => 'edit','corrects_invoice_id' => $originalId], self::string($original, 'environment'), 'invoice', $id);
            $this->readiness->evaluate($id, true, $open === null ? 'draft_unchanged' : 'draft_updated');
            return $open === null ? 'kor_draft_created' : 'kor_draft_updated';
        });
    }

    /** Credit note: szkic KOR z P_2 = numer credit note; faktura korygowana z linii `Faktura: <numer>` w `notes`. */
    public function fromCreditNote(FreshBooksCreditNote $note): string
    {
        if (!$this->enabled() || $note->document->deleted) {
            return 'kor_skipped';
        }
        return $this->db->transactional(function () use ($note): string {
            $document = $note->document;
            $environment = $this->configuration->environment();
            $hash = $document->sourceHash();
            $known = $this->db->fetchAssociative('SELECT id, status, source_hash FROM invoice WHERE environment=? AND freshbooks_credit_id=? FOR UPDATE', [$environment, $document->id]);
            if ($known !== false && (!in_array($known['status'], ['draft','ready','approved'], true) || $known['source_hash'] === $hash)) {
                if ($known['status'] === 'draft') {
                    $this->readiness->evaluate(self::string($known, 'id'), true);
                }
                return 'kor_unchanged';
            }
            $original = $note->originalNumber === null ? false : $this->db->fetchAssociative(
                "SELECT id FROM invoice WHERE environment=? AND direction='sale' AND kind='VAT' AND invoice_number=? AND status NOT IN ('cancelled_before_send','rejected')",
                [$environment, $note->originalNumber],
            );
            $originalId = $original === false ? null : self::string($original, 'id');
            $open = $known !== false ? ['id' => self::string($known, 'id')] : ($originalId === null ? null : $this->openKor($originalId));
            if ($open === null) {
                $id = $originalId !== null ? $this->insert($this->original($originalId), $document->number, $document->id, null) : $this->orphan($note);
                if ($id === null) {
                    return 'kor_skipped';
                }
                $created = true;
            } else {
                $id = $open['id'];
                $this->db->update('invoice', ['invoice_number' => $document->number,'freshbooks_credit_id' => $document->id,'updated_at' => $this->now()], ['id' => $id]);
                $created = false;
            }
            if ($originalId !== null) {
                $this->lines($id, $originalId, $this->credited($originalId, $document));
            }
            $this->db->executeStatement('UPDATE invoice SET source_hash=?, net_total=?, version=version+?, updated_at=? WHERE id=?', [$hash, (string) BigDecimal::of($document->net)->negated()->toScale(2), $created ? 0 : 1, $this->now(), $id]);
            $this->audit->append('invoice.kor_drafted', ['source' => 'credit_note','corrects_invoice_id' => $originalId], $environment, 'invoice', $id);
            $this->readiness->evaluate($id, true, $created ? 'draft_unchanged' : 'draft_updated');
            return $created ? 'kor_draft_created' : 'kor_draft_updated';
        });
    }

    /**
     * Pozycje „po” z credit note: uznanie pozycji o tej samej nazwie, a przy jednej pozycji faktury całe uznanie na nią.
     * Uznanie bez pasującej pozycji zostaje poza pozycjami; różnicę wykrywa warunek `ready` (suma pozycji ≠ uznanie).
     *
     * @return array<int,array{description:string,quantity:string,unit_net_price:string,net:string}>
     */
    private function credited(string $originalId, FreshBooksInvoice $credit): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT line_no, description, quantity, unit_net_price, net_amount FROM invoice_line WHERE invoice_id=? AND NOT state_before ORDER BY line_no', [$originalId]);
        $after = [];
        foreach ($rows as $row) {
            $no = is_int($row['line_no']) ? $row['line_no'] : 0;
            $description = self::string($row, 'description');
            $credited = BigDecimal::zero();
            foreach ($credit->lines as $line) {
                if (count($rows) === 1 || mb_strtolower(trim($line['name'])) === mb_strtolower(trim($description))) {
                    $credited = $credited->plus($line['net']);
                }
            }
            $net = BigDecimal::of(self::string($row, 'net_amount'))->minus($credited);
            $price = is_string($row['unit_net_price']) ? $row['unit_net_price'] : self::string($row, 'net_amount');
            $after[$no] = $credited->isZero()
                ? ['description' => $description,'quantity' => self::string($row, 'quantity'),'unit_net_price' => $price,'net' => self::string($row, 'net_amount')]
                : ($net->isZero() ? ['description' => $description,'quantity' => '0','unit_net_price' => $price,'net' => '0.00'] : ['description' => $description,'quantity' => '1','unit_net_price' => (string) $net->toScale(2),'net' => (string) $net->toScale(2)]);
        }
        return $after;
    }

    /**
     * Pozycje KOR: „przed” to kopia pozycji faktury korygowanej (`state_before`), „po” z tym samym `line_no`.
     *
     * @param array<int,array{description:string,quantity:string,unit_net_price:string,net:string}> $after
     */
    private function lines(string $korId, string $originalId, array $after): void
    {
        $now = $this->now();
        $this->db->executeStatement('DELETE FROM invoice_line WHERE invoice_id=?', [$korId]);
        $rows = $this->db->fetchAllAssociative('SELECT line_no, description, service_type, pkwiu, quantity, unit, unit_net_price, net_amount, vat_code, ryczalt_rate_pct, gtu FROM invoice_line WHERE invoice_id=? AND NOT state_before ORDER BY line_no', [$originalId]);
        $template = null;
        foreach ($rows as $row) {
            $template ??= $row;
            $no = is_int($row['line_no']) ? $row['line_no'] : 0;
            $this->db->insert('invoice_line', ['id' => UuidV7::generate($this->clock->now()),'invoice_id' => $korId,'state_before' => 'true','created_at' => $now,'updated_at' => $now] + $row);
            $line = $after[$no] ?? ['description' => self::string($row, 'description'),'quantity' => '0','unit_net_price' => is_string($row['unit_net_price']) ? $row['unit_net_price'] : self::string($row, 'net_amount'),'net' => '0.00'];
            $this->db->insert('invoice_line', [
                'id' => UuidV7::generate($this->clock->now()),'invoice_id' => $korId,'line_no' => $no,'state_before' => 'false','description' => $line['description'],
                'service_type' => $row['service_type'],'pkwiu' => $row['pkwiu'],'quantity' => $line['quantity'],'unit' => $row['unit'],'unit_net_price' => $line['unit_net_price'],
                'net_amount' => $line['net'],'vat_code' => $row['vat_code'],'ryczalt_rate_pct' => $row['ryczalt_rate_pct'],'gtu' => $row['gtu'],'created_at' => $now,'updated_at' => $now,
            ]);
            unset($after[$no]);
        }
        // Nowe pozycje bez odpowiednika „przed” (np. dopisane w edycji) mają kolejne numery.
        $next = count($rows);
        foreach ($after as $line) {
            $this->db->insert('invoice_line', [
                'id' => UuidV7::generate($this->clock->now()),'invoice_id' => $korId,'line_no' => ++$next,'state_before' => 'false','description' => $line['description'],
                'quantity' => $line['quantity'],'unit' => is_array($template) && is_string($template['unit']) ? $template['unit'] : 'usł.','unit_net_price' => $line['unit_net_price'],'net_amount' => $line['net'],
                'vat_code' => is_array($template) && is_string($template['vat_code']) ? $template['vat_code'] : '','ryczalt_rate_pct' => is_array($template) ? $template['ryczalt_rate_pct'] : null,'created_at' => $now,'updated_at' => $now,
            ]);
        }
    }

    /** @param array<string,mixed> $original */
    private function insert(array $original, ?string $number, ?string $creditId, ?string $currency): string
    {
        $id = UuidV7::generate($this->clock->now());
        $now = $this->now();
        $this->db->insert('invoice', [
            'id' => $id,'environment' => self::string($original, 'environment'),'direction' => 'sale','kind' => 'KOR','invoice_number' => $number,'counterparty_id' => self::string($original, 'counterparty_id'),
            'currency' => $currency ?? self::string($original, 'currency'),'issue_date' => $this->dates->today(),'net_total' => '0.00','vat_total' => '0.00','gross_total' => '0.00','status' => 'draft',
            'corrects_invoice_id' => self::string($original, 'id'),'corrected_invoice_number' => self::string($original, 'invoice_number'),'corrected_issue_date' => self::string($original, 'issue_date'),
            'corrected_ksef_number' => is_string($original['ksef_number']) ? $original['ksef_number'] : null,'freshbooks_credit_id' => $creditId,'created_at' => $now,'updated_at' => $now,
        ]);
        return $id;
    }

    /** Credit note do faktury nieznanej kokpitowi: szkic KOR bez pozycji, zablokowany do oznaczenia oryginału (NrKSeFN). */
    private function orphan(FreshBooksCreditNote $note): ?string
    {
        $document = $note->document;
        $party = FreshBooksLedger::party($document);
        if ($party === null || $document->clientId === null) {
            return null;
        }
        $id = UuidV7::generate($this->clock->now());
        $now = $this->now();
        $this->db->insert('invoice', [
            'id' => $id,'environment' => $this->configuration->environment(),'direction' => 'sale','kind' => 'KOR','invoice_number' => $document->number,
            'counterparty_id' => $this->counterparties->freshbooksClient($document->clientId, $party),'currency' => $document->currency,'issue_date' => $this->dates->today(),
            'net_total' => '0.00','vat_total' => '0.00','gross_total' => '0.00','status' => 'draft','corrected_invoice_number' => $note->originalNumber,
            'freshbooks_credit_id' => $document->id,'created_at' => $now,'updated_at' => $now,
        ]);
        return $id;
    }

    /** @return array<string,mixed> */
    private function original(string $id): array
    {
        $row = $this->db->fetchAssociative("SELECT id, environment, invoice_number, issue_date, ksef_number, counterparty_id, currency, net_total FROM invoice WHERE id=? AND direction='sale'", [$id]);
        return $row === false ? throw new \DomainException('Brak faktury korygowanej.') : $row;
    }

    /** @return array{id:string,status:string,freshbooks_credit_id:?string,source_hash:?string}|null otwarty szkic KOR faktury */
    private function openKor(string $originalId): ?array
    {
        $row = $this->db->fetchAssociative("SELECT id, status, freshbooks_credit_id, source_hash FROM invoice WHERE corrects_invoice_id=? AND kind='KOR' AND status IN " . self::OPEN . ' ORDER BY created_at DESC LIMIT 1 FOR UPDATE', [$originalId]);
        if ($row === false) {
            return null;
        }
        return ['id' => self::string($row, 'id'),'status' => self::string($row, 'status'),'freshbooks_credit_id' => is_string($row['freshbooks_credit_id']) ? $row['freshbooks_credit_id'] : null,'source_hash' => is_string($row['source_hash']) ? $row['source_hash'] : null];
    }

    private function enabled(): bool
    {
        return $this->settings->value(SettingKeys::FRESHBOOKS_SALES_ENABLED) === true;
    }

    private function now(): string
    {
        return $this->clock->now()->format(self::TIMESTAMP);
    }

    /** @param array<string,mixed> $row */
    private static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        return is_string($value) ? $value : throw new \LogicException(sprintf('Niepoprawne pole %s.', $key));
    }
}
