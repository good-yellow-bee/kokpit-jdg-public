<?php

declare(strict_types=1);

namespace App\Invoice;

use App\Audit\AuditLog;
use App\Config\ConnectorConfiguration;
use App\Counterparty\CounterpartyDirectory;
use App\Ledger\Subject1Ledger;
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
            $buyer = $this->counterparties->client($document->buyer);
            $taxPoint = $document->taxPointDate();
            $fx = $this->fx->amounts($document->currency, $taxPoint, $document->issueDate, false, $document->netTotal, $document->vatTotal);
            $corrects = $document->corrected['ksefNumber'] === null ? false : $this->db->fetchOne("SELECT id FROM invoice WHERE environment=? AND direction='sale' AND ksef_number=?", [$environment, $document->corrected['ksefNumber']]);
            $id = (string) UuidV7::generate($this->clock->now());
            $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
            // Szkic FreshBooks o tym numerze oddaje `freshbooks_id` fakturze z KSeF w tej samej transakcji (D-45).
            $draft = $document->kind === 'VAT' ? $this->db->fetchAssociative(
                "SELECT id,freshbooks_id,freshbooks_status FROM invoice WHERE environment=? AND direction='sale' AND kind='VAT' AND invoice_number=? AND status='draft' AND freshbooks_id IS NOT NULL FOR UPDATE",
                [$environment, $document->number],
            ) : false;
            if ($draft !== false) {
                $this->db->update('invoice', ['status' => 'cancelled_before_send','freshbooks_id' => null,'updated_at' => $now], ['id' => $draft['id']]);
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
                    'id' => (string) UuidV7::generate($this->clock->now()),'invoice_id' => $id,'line_no' => $line->lineNo,'state_before' => $line->stateBefore ? 'true' : 'false',
                    'description' => $line->description,'quantity' => $line->quantity,'unit' => $line->unit,'unit_net_price' => $line->unitNetPrice,'net_amount' => $line->netAmount,
                    'vat_code' => $line->vatCode,'vat_amount' => $line->vatAmount,'gtu' => $line->gtu,'created_at' => $now,'updated_at' => $now,
                ]);
            }
            $this->files->put($xml, $environment, 'xml_sent', 'application/xml', 'invoice', $id, (int) substr($document->issueDate, 0, 4));
            $this->audit->append('sale.imported_subject1', ['ksef_number' => $ksefNumber] + ($draft === false ? [] : ['freshbooks_draft_id' => $draft['id']]), $environment, 'invoice', $id);
            // Kurs PIT, stawki pozycji i, po przełączeniu, wiersze ewidencji (F2.2).
            $this->ledger->onImported($id);
            return $id;
        });
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
