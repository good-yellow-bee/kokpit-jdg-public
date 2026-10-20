<?php

declare(strict_types=1);

namespace App\Cost;

use App\Audit\AuditLog;
use App\Config\ConnectorConfiguration;
use App\Counterparty\CounterpartyDirectory;
use App\Invoice\Fa3Document;
use App\Invoice\Fa3Reader;
use App\Invoice\VatFx;
use App\Storage\FileStore;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Faktury kosztowe w stanie `imported`: Subject2 z KSeF (XML jako `cost_document`) i wpis ręczny spoza KSeF, który
 * nie powstaje bez pliku. Wpis ręczny łączy się z późniejszą fakturą z KSeF po sprzedawcy i numerze; różne kwoty
 * wracają do `imported` (docs/data-model.md, „Faktura kosztowa”).
 */
final readonly class CostImporter
{
    public const MANUAL_SOURCES = ['email','paper','cash_register','manual'];

    public function __construct(
        private Connection $db,
        private Fa3Reader $reader,
        private CounterpartyDirectory $counterparties,
        private VatFx $fx,
        private FileStore $files,
        private AuditLog $audit,
        private ClockInterface $clock,
        private ConnectorConfiguration $configuration,
    ) {}

    /** Faktura z eksportu Subject2; ponowny import tego samego numeru KSeF nic nie zmienia. */
    public function importKsef(string $xml, string $ksefNumber, \DateTimeImmutable $assignedAt): string
    {
        $document = $this->reader->read($xml);
        return $this->db->transactional(function () use ($xml, $document, $ksefNumber, $assignedAt): string {
            $environment = $this->configuration->environment();
            $known = $this->db->fetchOne('SELECT id FROM invoice WHERE environment=? AND ksef_number=?', [$environment, $ksefNumber]);
            if (is_string($known)) {
                return $known;
            }
            $supplier = $this->counterparties->supplier($document->seller);
            $manual = $this->db->fetchAssociative("SELECT * FROM invoice WHERE environment=? AND direction='cost' AND counterparty_id=? AND invoice_number=? AND ksef_number IS NULL FOR UPDATE", [$environment, $supplier, $document->number]);
            $now = $this->now();
            $assigned = $assignedAt->format('Y-m-d\TH:i:s.uP');
            if ($manual !== false) {
                $id = self::string($manual, 'id');
                $same = self::same($manual, $document);
                $fields = ['ksef_number' => $ksefNumber,'ksef_number_assigned_at' => $assigned,'schema_version' => $document->schemaVersion,'updated_at' => $now,'version' => self::int($manual, 'version') + 1];
                if (!$same) {
                    // KSeF jest wiążący: kwoty z XML zastępują wpis, a koszt wraca do przeglądu.
                    $taxPoint = is_string($manual['tax_point_override_reason']) ? self::string($manual, 'tax_point_date') : $document->taxPointDate();
                    $import = is_string($manual['import_services']);
                    $fx = $this->fx->amounts($document->currency, $taxPoint, $document->issueDate, $import, $document->netTotal, $document->vatTotal);
                    $fields += ['currency' => $document->currency,'net_total' => $document->netTotal,'vat_total' => $document->vatTotal,'gross_total' => $document->grossTotal,'issue_date' => $document->issueDate,'service_date' => $document->serviceDate,'tax_point_date' => $taxPoint,
                        'fx_vat_rate_id' => $fx['fx_vat_rate_id'],'net_pln' => $fx['net_pln'],'vat_pln' => $fx['vat_pln'],'cost_status' => 'imported','vat_period_start' => null];
                    if ($import && $fx['net_pln'] !== null && $taxPoint !== null) {
                        $fields['self_assessed_vat_pln'] = $this->fx->selfAssessed($fx['net_pln'], $taxPoint, $manual['vat_exempt'] === true);
                    }
                }
                // Wpis ręczny nie ma pozycji: dostaje je z XML (bez DELETE, `invoice_line` jest bez prawa usuwania).
                if ($this->db->fetchOne('SELECT 1 FROM invoice_line WHERE invoice_id=?', [$id]) === false) {
                    $this->lines($id, $document);
                }
                $this->db->update('invoice', $fields, ['id' => $id]);
                $this->store($xml, $id, $document->issueDate);
                $this->audit->append('cost.ksef_linked', ['ksef_number' => $ksefNumber,'amounts_changed' => !$same], $environment, 'invoice', $id);
                return $id;
            }
            $id = UuidV7::generate($this->clock->now());
            $taxPoint = $document->taxPointDate();
            $fx = $this->fx->amounts($document->currency, $taxPoint, $document->issueDate, false, $document->netTotal, $document->vatTotal);
            $exempt = $document->lines !== [] && array_filter($document->lines, static fn($line): bool => $line->vatCode !== 'zw') === [];
            $this->db->insert('invoice', [
                'id' => $id,'environment' => $environment,'direction' => 'cost','kind' => $document->kind,'invoice_number' => $document->number,'counterparty_id' => $supplier,
                'currency' => $document->currency,'ksef_number' => $ksefNumber,'schema_version' => $document->schemaVersion,'issue_date' => $document->issueDate,'service_date' => $document->serviceDate,
                'service_period_start' => $document->periodStart,'service_period_end' => $document->periodEnd,'ksef_number_assigned_at' => $assigned,'tax_point_date' => $taxPoint,
                'net_total' => $document->netTotal,'vat_total' => $document->vatTotal,'gross_total' => $document->grossTotal,
                'fx_vat_rate_id' => $fx['fx_vat_rate_id'],'net_pln' => $fx['net_pln'],'vat_pln' => $fx['vat_pln'],
                'cost_status' => 'imported','source' => 'ksef','vat_exempt' => $exempt ? 'true' : 'false','deductible' => $exempt ? 'false' : 'true','is_fixed_asset' => 'false',
                'corrected_invoice_number' => $document->corrected['number'],'corrected_issue_date' => $document->corrected['issueDate'],'corrected_ksef_number' => $document->corrected['ksefNumber'],
                'correction_reason' => $document->corrected['reason'],'correction_type' => $document->corrected['type'],
                'created_at' => $now,'updated_at' => $now,
            ]);
            $this->lines($id, $document);
            $this->store($xml, $id, $document->issueDate);
            $this->audit->append('cost.imported', ['source' => 'ksef','ksef_number' => $ksefNumber], $environment, 'invoice', $id);
            return $id;
        });
    }

    /** Koszt spoza KSeF tylko z plikiem `cost_document` (mail, papier, kasa, wpis ręczny). */
    public function recordManual(ManualCost $cost, string $file, string $mediaType, string $userId): string
    {
        if ($file === '') {
            throw new \DomainException('Koszt spoza KSeF wymaga pliku dokumentu.');
        }
        if (!in_array($cost->source, self::MANUAL_SOURCES, true)) {
            throw new \DomainException('Źródło kosztu spoza KSeF: e-mail, papier, kasa albo wpis ręczny.');
        }
        if ($cost->importServices !== null && !in_array($cost->importServices, ['eu_28b','other'], true)) {
            throw new \DomainException('Import usług: eu_28b albo other.');
        }
        if ($cost->importServices !== null && $cost->taxPointDate === null) {
            throw new \DomainException('Import usług wymaga daty obowiązku podatkowego.');
        }
        foreach ([$cost->netTotal, $cost->vatTotal, $cost->grossTotal] as $amount) {
            if (!preg_match('/^-?[0-9]{1,16}(\.[0-9]{1,2})?$/D', $amount)) {
                throw new \DomainException('Kwota ma najwyżej dwa miejsca po przecinku.');
            }
        }
        return $this->db->transactional(function () use ($cost, $file, $mediaType, $userId): string {
            $environment = $this->configuration->environment();
            $supplier = $cost->counterpartyId ?? $this->counterparties->manualSupplier($cost->supplier ?? throw new \DomainException('Podaj dostawcę.'));
            $duplicate = $this->db->fetchOne("SELECT id FROM invoice WHERE environment=? AND direction='cost' AND counterparty_id=? AND invoice_number=?", [$environment, $supplier, $cost->invoiceNumber]);
            if (is_string($duplicate)) {
                throw new \DomainException('Ten dokument dostawcy już jest w kokpicie.');
            }
            $id = UuidV7::generate($this->clock->now());
            $import = $cost->importServices !== null;
            $fx = $this->fx->amounts($cost->currency, $cost->taxPointDate, $cost->issueDate, $import, $cost->netTotal, $cost->vatTotal);
            $selfAssessed = $import && $fx['net_pln'] !== null && $cost->taxPointDate !== null ? $this->fx->selfAssessed($fx['net_pln'], $cost->taxPointDate, $cost->vatExempt) : null;
            $now = $this->now();
            $this->db->insert('invoice', [
                'id' => $id,'environment' => $environment,'direction' => 'cost','kind' => $cost->kind,'invoice_number' => $cost->invoiceNumber,'counterparty_id' => $supplier,
                'currency' => $cost->currency,'issue_date' => $cost->issueDate,'received_on' => $cost->receivedOn,'tax_point_date' => $cost->taxPointDate,'ksef_issue_mode' => 'outside_ksef',
                'net_total' => $cost->netTotal,'vat_total' => $cost->vatTotal,'gross_total' => $cost->grossTotal,
                'fx_vat_rate_id' => $fx['fx_vat_rate_id'],'net_pln' => $fx['net_pln'],'vat_pln' => $fx['vat_pln'],
                'cost_status' => 'imported','source' => $cost->source,'import_services' => $cost->importServices,'vat_exempt' => $cost->vatExempt ? 'true' : 'false',
                'self_assessed_vat_pln' => $selfAssessed,'deductible' => $cost->deductible ? 'true' : 'false','jpk_doc_type' => $cost->jpkDocType,'is_fixed_asset' => $cost->fixedAsset ? 'true' : 'false',
                'created_at' => $now,'updated_at' => $now,
            ]);
            $this->files->put($file, $environment, 'cost_document', $mediaType, 'invoice', $id, (int) substr($cost->issueDate, 0, 4));
            $this->audit->append('cost.imported', ['source' => $cost->source], $environment, 'invoice', $id, $userId);
            return $id;
        });
    }

    private function lines(string $invoiceId, Fa3Document $document): void
    {
        $now = $this->now();
        foreach ($document->lines as $line) {
            $this->db->insert('invoice_line', [
                'id' => UuidV7::generate($this->clock->now()),'invoice_id' => $invoiceId,'line_no' => $line->lineNo,'state_before' => $line->stateBefore ? 'true' : 'false',
                'description' => $line->description,'quantity' => $line->quantity,'unit' => $line->unit,'unit_net_price' => $line->unitNetPrice,'net_amount' => $line->netAmount,
                'vat_code' => $line->vatCode,'vat_amount' => $line->vatAmount,'gtu' => $line->gtu,'created_at' => $now,'updated_at' => $now,
            ]);
        }
    }

    private function store(string $xml, string $invoiceId, string $issueDate): void
    {
        $this->files->put($xml, $this->configuration->environment(), 'cost_document', 'application/xml', 'invoice', $invoiceId, (int) substr($issueDate, 0, 4));
    }

    /** @param array<string,mixed> $row */
    private static function same(array $row, Fa3Document $document): bool
    {
        return $row['currency'] === $document->currency
            && BigDecimal::of(self::string($row, 'net_total'))->isEqualTo($document->netTotal)
            && BigDecimal::of(self::string($row, 'vat_total'))->isEqualTo($document->vatTotal)
            && BigDecimal::of(self::string($row, 'gross_total'))->isEqualTo($document->grossTotal);
    }

    /** @param array<string,mixed> $row */
    private static function string(array $row, string $key): string
    {
        return is_string($row[$key] ?? null) ? $row[$key] : throw new \LogicException(sprintf('Brak pola %s.', $key));
    }

    /** @param array<string,mixed> $row */
    private static function int(array $row, string $key): int
    {
        return is_int($row[$key] ?? null) ? $row[$key] : (is_numeric($row[$key] ?? null) ? (int) $row[$key] : throw new \LogicException(sprintf('Brak pola %s.', $key)));
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s.uP');
    }
}
