<?php

declare(strict_types=1);

namespace App\Invoice;

use App\Config\ConnectorConfiguration;
use App\Connector\FreshBooks\FreshBooksConnector;
use App\Connector\FreshBooks\FreshBooksInvoice;
use App\Ledger\ServiceMap;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use App\Storage\FileStore;
use App\Xml\Fa3Builder;
use App\Xml\Fa3BuildRefused;
use App\Xml\Fa3Invoice;
use App\Xml\Fa3InvoiceLine;
use App\Xml\Fa3Scenario;
use Doctrine\DBAL\Connection;

/**
 * Okres równoległy, krok 3 przejścia (docs/modules/ksef.md, „Przejście krok po kroku”): faktura FreshBooks połączona
 * z fakturą Subject1 przez `freshbooks_id` przechodzi przez mapowanie FA(3) kokpitu, z P_1 i P_2 z XML Subject1 tylko
 * do porównania. Porównuje Podmiot1, Podmiot2, P_6 albo `OkresFa`, pozycje, P_12, P_13_x, P_14_x i P_14_xW, P_15,
 * adnotacje (z P_18) i `KodWaluty`; hash i KOD I pomija. Tylko odczyt: bez `approval`, bez zapisu w bazie, w KSeF
 * i we FreshBooks; z FreshBooks tylko `GET` faktury.
 */
final readonly class KsefParallelCompare
{
    public const COLUMNS = ['p1','p2','ksef_number','freshbooks_id','result','field','freshbooks','subject1'];
    /** Porównywane części FA(3); ścieżki względem `/Faktura`. */
    private const SECTIONS = ['Podmiot1','Podmiot2','Fa/KodWaluty','Fa/P_6','Fa/OkresFa','Fa/P_13_*','Fa/P_14_*','Fa/P_15','Fa/Adnotacje','Fa/FaWiersz'];

    public function __construct(
        private Connection $db,
        private FreshBooksConnector $freshbooks,
        private Fa3Builder $builder,
        private FileStore $files,
        private SettingStore $settings,
        private ConnectorConfiguration $configuration,
    ) {}

    /** @return list<array{p1:string,p2:string,ksef_number:string,freshbooks_id:string,result:string,field:string,freshbooks:string,subject1:string}> */
    public function compare(string $month): array
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
        if ($start === false || $start->format('Y-m') !== $month) {
            throw new \InvalidArgumentException('Miesiąc w formacie RRRR-MM.');
        }
        $invoices = $this->db->fetchAllAssociative(
            "SELECT i.id, i.invoice_number, i.issue_date, i.ksef_number, i.freshbooks_id, i.counterparty_id, i.tax_scenario, i.service_date, i.service_period_start, i.service_period_end
             FROM invoice i WHERE i.environment=? AND i.direction='sale' AND i.kind='VAT' AND i.freshbooks_id IS NOT NULL AND i.ksef_number IS NOT NULL
             AND i.issue_date >= ? AND i.issue_date < ? AND NOT EXISTS (SELECT 1 FROM approval a WHERE a.subject_id=i.id) ORDER BY i.issue_date, i.invoice_number, i.id",
            [$this->configuration->environment(), $start->format('Y-m-d'), $start->modify('+1 month')->format('Y-m-d')],
        );
        $rows = [];
        foreach ($invoices as $invoice) {
            $base = ['p1' => self::text($invoice, 'issue_date'),'p2' => self::text($invoice, 'invoice_number'),'ksef_number' => self::text($invoice, 'ksef_number'),'freshbooks_id' => self::text($invoice, 'freshbooks_id')];
            $xml = $this->subject1Xml(self::text($invoice, 'id'));
            if ($xml === null) {
                $rows[] = $base + ['result' => 'xml_missing','field' => '','freshbooks' => '','subject1' => ''];
                continue;
            }
            $subject1 = self::fields($xml);
            // P_1 i P_2 z XML Subject1, tylko do porównania (bez stanu `ready` i bez `approval`).
            $base['p1'] = $subject1['Fa/P_1'] ?? $base['p1'];
            $base['p2'] = $subject1['Fa/P_2'] ?? $base['p2'];
            $source = $this->freshbooks->invoice($base['freshbooks_id']);
            if ($source === null) {
                $rows[] = $base + ['result' => 'freshbooks_missing','field' => '','freshbooks' => '','subject1' => ''];
                continue;
            }
            try {
                $built = self::fields($this->builder->build($this->dto($invoice, $source, $base['p2']), $base['p1'])->xml);
            } catch (Fa3BuildRefused $refused) {
                $rows[] = $base + ['result' => 'difference','field' => 'mapping','freshbooks' => implode('; ', $refused->problems),'subject1' => ''];
                continue;
            }
            unset($built['Fa/P_1'], $built['Fa/P_2'], $subject1['Fa/P_1'], $subject1['Fa/P_2']);
            $differences = 0;
            foreach (array_unique(array_merge(array_keys($built), array_keys($subject1))) as $field) {
                if (($built[$field] ?? null) !== ($subject1[$field] ?? null)) {
                    $rows[] = $base + ['result' => 'difference','field' => $field,'freshbooks' => $built[$field] ?? '','subject1' => $subject1[$field] ?? ''];
                    ++$differences;
                }
            }
            if ($differences === 0) {
                $rows[] = $base + ['result' => 'match','field' => '','freshbooks' => '','subject1' => ''];
            }
        }
        return $rows;
    }

    /** @param list<array<string,string>> $rows */
    public static function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new \RuntimeException('Nie można zbudować CSV.');
        }
        fputcsv($handle, self::COLUMNS, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn(string $column): string => $row[$column] ?? '', self::COLUMNS), ',', '"', '');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        return is_string($csv) ? $csv : '';
    }

    /**
     * Pola liściowe porównywanych części: ścieżka (z numerem powtórzenia, np. `Fa/FaWiersz[2]/P_11`) → tekst.
     *
     * @return array<string,string>
     */
    public static function fields(string $xml): array
    {
        $document = new \DOMDocument();
        if (!$document->loadXML($xml, LIBXML_NONET)) {
            throw new \UnexpectedValueException('Niepoprawny XML FA(3).');
        }
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('f', Fa3Reader::NS);
        $fields = [];
        foreach (['Fa/P_1','Fa/P_2'] as $key) {
            $value = $xpath->evaluate('string(/f:Faktura/f:' . str_replace('/', '/f:', $key) . ')');
            if (is_string($value) && trim($value) !== '') {
                $fields[$key] = trim($value);
            }
        }
        foreach (self::SECTIONS as $section) {
            $parts = explode('/', $section);
            $last = (string) array_pop($parts);
            $query = '/f:Faktura' . implode('', array_map(static fn(string $part): string => '/f:' . $part, $parts))
                . (str_ends_with($last, '*') ? '/*[starts-with(local-name(), "' . rtrim($last, '*') . '")]' : '/f:' . $last);
            $seen = [];
            foreach ($xpath->query($query) ?: [] as $node) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }
                $prefix = implode('/', $parts);
                $name = ($prefix === '' ? '' : $prefix . '/') . $node->localName;
                $seen[$name] = ($seen[$name] ?? 0) + 1;
                self::leaves($node, $node->localName === 'FaWiersz' ? sprintf('%s[%d]', $name, $seen[$name]) : $name, $fields);
            }
        }
        return $fields;
    }

    /** @param array<string,string> $fields */
    private static function leaves(\DOMElement $node, string $path, array &$fields): void
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $children[] = $child;
            }
        }
        if ($children === []) {
            $fields[$path] = trim($node->textContent);
            return;
        }
        $count = [];
        foreach ($children as $child) {
            $count[$child->localName] = ($count[$child->localName] ?? 0) + 1;
            self::leaves($child, $path . '/' . $child->localName . ($count[$child->localName] > 1 ? '[' . $count[$child->localName] . ']' : ''), $fields);
        }
    }

    /** XML Subject1 faktury (jedyny `xml_sent` z właścicielem `invoice`). */
    private function subject1Xml(string $invoiceId): ?string
    {
        $ids = $this->db->fetchFirstColumn("SELECT id FROM stored_file WHERE owner_table='invoice' AND owner_id=? AND kind='xml_sent' AND deleted_at IS NULL", [$invoiceId]);
        return count($ids) === 1 && is_string($ids[0]) ? $this->files->read($ids[0]) : null;
    }

    /** @param array<string,mixed> $invoice */
    private function dto(array $invoice, FreshBooksInvoice $source, string $number): Fa3Invoice
    {
        $scenario = Fa3Scenario::tryFrom(self::text($invoice, 'tax_scenario')) ?? throw new Fa3BuildRefused(['Faktura bez tax_scenario.']);
        $day = self::nullable($invoice, 'service_date') ?? self::nullable($invoice, 'service_period_end') ?? self::text($invoice, 'issue_date');
        $vatCode = SalesConditions::vatCode($scenario->value, $this->settings->value(SettingKeys::VAT_STANDARD_RATE, $day)) ?? throw new Fa3BuildRefused(['Brak vat.standard_rate w ustawieniach.']);
        $map = ServiceMap::fromSetting($this->settings->value(SettingKeys::RYCZALT_SERVICE_MAP, $day));
        $lines = [];
        foreach ($source->lines as $index => $line) {
            $entry = $map->match($line['name'], $line['description'] ?? $line['name']);
            $lines[] = new Fa3InvoiceLine($index + 1, trim($line['name']) !== '' ? $line['name'] : ($line['description'] ?? '-'), 'usł.', $line['qty'] ?? '1', $line['unit_cost'] ?? $line['net'], $line['net'], $vatCode, null, $entry['pkwiu'] ?? null, false);
        }
        return new Fa3Invoice('VAT', $number, $scenario, $source->currency, SalesInvoiceSubject::buyer($this->party(self::text($invoice, 'counterparty_id'))), $lines, self::nullable($invoice, 'service_date'), self::nullable($invoice, 'service_period_start'), self::nullable($invoice, 'service_period_end'));
    }

    /** @return array{country:string,nip:?string,eu_vat_prefix:?string,eu_vat_number:?string,foreign_tax_id:?string,name:string,address:array{country:string,line1:?string,line2:?string}} */
    private function party(string $counterpartyId): array
    {
        $row = $this->db->fetchAssociative('SELECT name, country, nip, eu_vat_prefix, eu_vat_number, foreign_tax_id, address FROM counterparty WHERE id=?', [$counterpartyId]);
        if ($row === false) {
            throw new \DomainException('Brak kontrahenta faktury.');
        }
        $address = json_decode(self::nullable($row, 'address') ?? '{}', true, 4, JSON_THROW_ON_ERROR);
        $address = is_array($address) ? $address : [];
        $line = static function (string $key) use ($address): ?string {
            $value = $address[$key] ?? null;
            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        };
        $country = self::text($row, 'country');
        return [
            'country' => $country,'nip' => self::nullable($row, 'nip'),'eu_vat_prefix' => self::nullable($row, 'eu_vat_prefix'),'eu_vat_number' => self::nullable($row, 'eu_vat_number'),
            'foreign_tax_id' => self::nullable($row, 'foreign_tax_id'),'name' => self::text($row, 'name'),
            'address' => ['country' => $line('country') ?? $country,'line1' => $line('line1'),'line2' => $line('line2')],
        ];
    }

    /** @param array<string,mixed> $row */
    private static function text(array $row, string $key): string
    {
        return self::nullable($row, $key) ?? throw new \UnexpectedValueException('Brak pola ' . $key . '.');
    }

    /** @param array<string,mixed> $row */
    private static function nullable(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }
}
