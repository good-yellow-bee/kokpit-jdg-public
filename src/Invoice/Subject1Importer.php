<?php

declare(strict_types=1);

namespace App\Invoice;

use App\Approval\ApprovalTransitions;
use App\Audit\AuditLog;
use App\Config\ConnectorConfiguration;
use App\Counterparty\CounterpartyDirectory;
use App\Ledger\Periods;
use App\Ledger\Subject1Ledger;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use App\Storage\FileStore;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Twoja faktura wystawiona poza kokpitem i pobrana z eksportu KSeF (Subject1): wchodzi od razu jako `accepted`,
 * bez `approval`, a bajty z KSeF są jej `xml_sent` (docs/data-model.md, „Faktura sprzedaży”). Ponowny import tego
 * samego numeru KSeF nic nie zmienia.
 */
final readonly class Subject1Importer
{
    /** Kraje UE dla `tax_scenario`; XI (Irlandia Północna) ma KodUE, ale usługi to eksport (docs/tax-rules.md). */
    public const EU = ['AT','BE','BG','CY','CZ','DE','DK','EE','EL','ES','FI','FR','HR','HU','IE','IT','LT','LU','LV','MT','NL','PT','RO','SE','SI','SK'];

    public function __construct(
        private Connection $db,
        private Fa3Reader $reader,
        private CounterpartyDirectory $counterparties,
        private VatFx $fx,
        private FileStore $files,
        private AuditLog $audit,
        private ClockInterface $clock,
        private ConnectorConfiguration $configuration,
        private Subject1Ledger $ledger,
        private Periods $periods,
        private ?SettingStore $settings = null,
        private ?ApprovalTransitions $transitions = null,
        private ?SalesTasks $tasks = null,
    ) {}

    public function import(string $xml, string $ksefNumber, \DateTimeImmutable $assignedAt): string
    {
        $document = $this->reader->read($xml);
        return $this->db->transactional(function () use ($xml, $document, $ksefNumber, $assignedAt): string {
            $environment = $this->configuration->environment();
            $known = $this->db->fetchOne('SELECT id FROM invoice WHERE environment=? AND ksef_number=?', [$environment, $ksefNumber]);
            if (is_string($known)) {
                return $known;
            }
            // Własna wysyłka w `submitted` albo `unknown` o tym `kind` i P_2: rozstrzyga sha256 `xml_sent` (F3.3, D-54).
            $sent = $this->db->fetchOne(
                "SELECT id FROM invoice WHERE environment=? AND direction='sale' AND kind=? AND invoice_number=? AND status IN ('submitted','unknown') FOR UPDATE",
                [$environment, $document->kind, $document->number],
            );
            if (is_string($sent) && $this->matchSent($sent, $xml, $ksefNumber, $assignedAt)) {
                return $sent;
            }
            $buyer = $this->counterparties->client($document->buyer);
            $taxPoint = $document->taxPointDate();
            $fx = $this->fx->amounts($document->currency, $taxPoint, $document->issueDate, false, $document->netTotal, $document->vatTotal);
            $corrects = $document->corrected['ksefNumber'] === null ? false : $this->db->fetchOne("SELECT id FROM invoice WHERE environment=? AND direction='sale' AND ksef_number=?", [$environment, $document->corrected['ksefNumber']]);
            $id = UuidV7::generate($this->clock->now());
            $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
            // Szkic o tym `kind` i P_2 (`draft`, `ready`, `approved` bez wysyłki) ustępuje fakturze z KSeF w tej samej transakcji:
            // żywe zatwierdzenie → `cancelled` z `manual_invoice`, szkic → `cancelled_before_send`, `freshbooks_id` przechodzi (D-45, F3.2).
            $draft = $this->db->fetchAssociative(
                "SELECT id,freshbooks_id,freshbooks_status FROM invoice WHERE environment=? AND direction='sale' AND kind=? AND invoice_number=? AND status IN ('draft','ready','approved') FOR UPDATE",
                [$environment, $document->kind, $document->number],
            );
            if ($draft !== false) {
                $this->cancelDraft(is_string($draft['id']) ? $draft['id'] : throw new \LogicException('Niepoprawny szkic.'), $now);
            }
            $snapshot = ['name' => $document->buyer->name,'nip' => $document->buyer->nip,'eu_vat_prefix' => $document->buyer->euVatPrefix,'eu_vat_number' => $document->buyer->euVatNumber,
                'id_country' => $document->buyer->idCountry,'foreign_tax_id' => $document->buyer->foreignTaxId,'no_id' => $document->buyer->noId,'address' => $document->buyer->address];
            $this->db->insert('invoice', [
                'id' => $id,'environment' => $environment,'direction' => 'sale','kind' => $document->kind,'invoice_number' => $document->number,'counterparty_id' => $buyer,
                'currency' => $document->currency,'ksef_number' => $ksefNumber,'schema_version' => $document->schemaVersion,'issue_date' => $document->issueDate,'service_date' => $document->serviceDate,
                'service_period_start' => $document->periodStart,'service_period_end' => $document->periodEnd,'ksef_number_assigned_at' => $assignedAt->format('Y-m-d\TH:i:s.uP'),
                'tax_point_date' => $taxPoint,'revenue_date' => $document->kind === 'VAT' ? Subject1Ledger::revenueDate($document) : null,'vat_period_start' => $taxPoint === null ? null : substr($taxPoint, 0, 7) . '-01',
                'net_total' => $document->netTotal,'vat_total' => $document->vatTotal,'gross_total' => $document->grossTotal,
                'fx_vat_rate_id' => $fx['fx_vat_rate_id'],'net_pln' => $fx['net_pln'],'vat_pln' => $fx['vat_pln'],
                'status' => 'accepted','tax_scenario' => self::scenario($document->buyer),'buyer_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'corrected_invoice_number' => $document->corrected['number'],'corrected_issue_date' => $document->corrected['issueDate'],'corrected_ksef_number' => $document->corrected['ksefNumber'],
                'correction_reason' => $document->corrected['reason'],'correction_type' => $document->corrected['type'],
                'corrects_invoice_id' => is_string($corrects) ? $corrects : null,'created_at' => $now,'updated_at' => $now,
                'freshbooks_id' => $draft === false ? null : $draft['freshbooks_id'],'freshbooks_status' => $draft === false ? null : $draft['freshbooks_status'],
            ]);
            foreach ($document->lines as $line) {
                $this->db->insert('invoice_line', [
                    'id' => UuidV7::generate($this->clock->now()),'invoice_id' => $id,'line_no' => $line->lineNo,'state_before' => $line->stateBefore ? 'true' : 'false',
                    'description' => $line->description,'quantity' => $line->quantity,'unit' => $line->unit,'unit_net_price' => $line->unitNetPrice,'net_amount' => $line->netAmount,
                    'vat_code' => $line->vatCode,'vat_amount' => $line->vatAmount,'gtu' => $line->gtu,'created_at' => $now,'updated_at' => $now,
                ]);
            }
            $this->files->put($xml, $environment, 'xml_sent', 'application/xml', 'invoice', $id, (int) substr($document->issueDate, 0, 4));
            $this->audit->append('sale.imported_subject1', ['ksef_number' => $ksefNumber] + ($draft === false ? [] : ['freshbooks_draft_id' => $draft['id']]), $environment, 'invoice', $id);
            if ($document->kind === 'KOR') {
                // KOR ze skutkiem w zamkniętym M wymaga korekty JPK_V7M: TypKorekty 1 to okres faktury pierwotnej, inaczej okres KOR.
                $period = $document->corrected['type'] === 1 && $document->corrected['issueDate'] !== null ? $this->originalPeriod($corrects, $document->corrected['issueDate']) : ($taxPoint ?? $document->issueDate);
                $this->periods->correctionRequired($period, 'invoice_kor', 'invoice', $id);
            }
            if ($draft === false && $this->settings?->value(SettingKeys::FRESHBOOKS_SALES_ENABLED, $document->issueDate) === true) {
                // Od go-live F3 faktura bez lokalnego wiersza to zadanie „faktura spoza kokpitu” (docs/modules/ksef.md).
                $this->tasks?->open($id, 'outside_cockpit', false);
            }
            // Kurs PIT, stawki pozycji i, po przełączeniu, wiersze ewidencji (F2.2).
            $this->ledger->onImported($id);
            return $id;
        });
    }

    /**
     * Zapasowa rekoncyliacja z syncu Subject1 (token `ksef_token_read`, docs/modules/ksef.md, „Skutki zapisu”): bajty
     * z KSeF zgodne z którymkolwiek `xml_sent` faktury → `accepted` z tym numerem; różne → kolizja P_2: `rejected`
     * z `p2_collision` i alert, a faktura z KSeF wchodzi jak przy braku lokalnego wiersza.
     *
     * @return bool czy faktura z KSeF to własna wysyłka
     */
    private function matchSent(string $invoiceId, string $xml, string $ksefNumber, \DateTimeImmutable $assignedAt): bool
    {
        $live = $this->db->fetchAssociative("SELECT * FROM approval WHERE subject_type IN ('sales_invoice','correction_invoice') AND subject_id=? AND status IN ('dispatching','submitted','unknown') FOR UPDATE", [$invoiceId]);
        if ($live === false || $live['status'] === 'dispatching') {
            throw new \DomainException('Faktura o tym numerze ma wysyłkę w toku; rozstrzyga SubmissionReconciler.');
        }
        if ($this->transitions === null) {
            throw new \LogicException('Brak ApprovalTransitions dla rekoncyliacji z Subject1.');
        }
        $sent = $this->db->fetchFirstColumn("SELECT sha256 FROM stored_file WHERE owner_table='invoice' AND owner_id=? AND kind='xml_sent'", [$invoiceId]);
        $event = $live['status'] === 'unknown' ? 'resolve' : 'accepted';
        $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
        if (in_array(hash('sha256', $xml), $sent, true)) {
            $refs = ApprovalTransitions::refs($live) + ['ksef_number' => $ksefNumber,'acquisition_date' => $assignedAt->format('Y-m-d\TH:i:s.uP'),'reconciled_by' => 'subject1'];
            $this->transitions->apply($live, 'accepted', $event, ['result_code' => 'subject1_match','finished_at' => $now,'external_refs' => json_encode($refs, JSON_THROW_ON_ERROR)], ['result_code' => 'subject1_match','reconciled_by' => 'subject1']);
            return true;
        }
        $this->transitions->apply($live, 'rejected', $event, ['result_code' => 'p2_collision','finished_at' => $now], ['result_code' => 'p2_collision','reconciled_by' => 'subject1']);
        return false;
    }

    /** Szkic przed wysyłką ustępuje fakturze spoza kokpitu; przy `dispatching` i dalej rozstrzyga `SubmissionReconciler` (F3.3). */
    private function cancelDraft(string $draftId, string $now): void
    {
        $live = $this->db->fetchAssociative("SELECT * FROM approval WHERE subject_type IN ('sales_invoice','correction_invoice') AND subject_id=? AND status IN ('pending','approved','dispatching','submitted','unknown') FOR UPDATE", [$draftId]);
        if ($live !== false && !in_array($live['status'], ['pending','approved'], true)) {
            throw new \DomainException('Szkic o tym numerze ma wysyłkę w toku; rozstrzyga SubmissionReconciler.');
        }
        if ($live !== false) {
            if ($this->transitions === null) {
                throw new \LogicException('Brak ApprovalTransitions dla anulowania z manual_invoice.');
            }
            $this->transitions->apply($live, 'cancelled', 'cancel', ['cancel_reason' => 'manual_invoice','finished_at' => $now], ['cancel_reason' => 'manual_invoice']);
        }
        $this->db->update('invoice', ['status' => 'cancelled_before_send','freshbooks_id' => null,'ready_at' => null,'invoice_number_locked_at' => null,'ready_blockers' => null,'updated_at' => $now], ['id' => $draftId]);
    }

    /** Okres VAT faktury pierwotnej z bazy, a bez niej miesiąc jej wystawienia z KOR. */
    private function originalPeriod(mixed $correctedId, string $correctedIssueDate): string
    {
        $period = is_string($correctedId) ? $this->db->fetchOne('SELECT vat_period_start FROM invoice WHERE id=?', [$correctedId]) : false;
        return is_string($period) ? $period : $correctedIssueDate;
    }

    /** `tax_scenario` z Podmiot2 (docs/tax-rules.md, „Scenariusze”). */
    public static function scenario(Fa3Party $buyer): string
    {
        if ($buyer->nip !== null) {
            return 'pl_b2b';
        }
        if ($buyer->euVatNumber !== null && $buyer->euVatPrefix !== null) {
            return $buyer->euVatPrefix === 'XI' ? 'non_eu_b2b' : 'eu_b2b';
        }
        $country = $buyer->idCountry ?? $buyer->address['country'];
        return in_array($country === 'GR' ? 'EL' : $country, self::EU, true) ? 'eu_b2b_unidentified' : 'non_eu_b2b';
    }
}
