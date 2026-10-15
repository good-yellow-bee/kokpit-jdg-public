<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Audit\AuditLog;
use App\Ledger\JpkDryRun;
use App\Ledger\JpkEwpSchema;
use App\Security\Redactor;
use App\Security\SecretStore;
use App\Setting\SettingStore;
use App\Time\BusinessDate;
use App\Xml\JpkEwpBuilder;
use App\Xml\JpkStBuilder;
use App\Xml\JpkTaxpayer;
use App\Xml\JpkV7mBuilder;
use App\Xml\XsdCatalog;
use App\Xml\XsdDictionary;
use App\Xml\XsdValidator;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/**
 * Fikcyjny zbiór dry runu F2.4: import `ledger-2026-jan-nov.csv`, wiersze z `jpk-2026-dry-run.json` i ustawienia firmy;
 * wykaz środków trwałych JPK_ST(1) z `fixed-assets-2026.json` (F5.2).
 */
final class JpkTestTools
{
    public const NS = 'http://jpk.mf.gov.pl/wzor/2024/10/30/10301/';
    public const ETD = 'http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2022/01/05/eD/DefinicjeTypy/';
    public const ST_NS = 'http://jpk.mf.gov.pl/wzor/2024/11/26/11262/';
    /** Pola wiersza `EWP` JPK_ST(1) w kolejności XSD; `KSeF` to węzeł z F_15. */
    public const ST_FIELDS = ['F_1','F_2','F_3','F_4','F_5','F_6','F_7','F_8','F_9','F_10','F_11','F_12','F_13','F_14','F_15','F_16'];
    /** @return array{company_identity:array<string,string>,auth_data_birth_date:string,entries:list<array<string,mixed>>} */
    public static function dataset(): array
    {
        $data = json_decode(LedgerTestTools::fixture('jpk-2026-dry-run.json'), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['company_identity'] ?? null) || !is_string($data['auth_data_birth_date'] ?? null) || !is_array($data['entries'] ?? null)) {
            throw new \RuntimeException('Niepoprawny fikcyjny zbiór dry runu.');
        }
        /** @var array{company_identity:array<string,string>,auth_data_birth_date:string,entries:list<array<string,mixed>>} $data */
        return $data;
    }
    public static function settings(Connection $db, MockClock $clock): SettingStore
    {
        return new SettingStore($db, $clock, new BusinessDate($clock), new AuditLog($db, $clock, new Redactor()), ConnectorTestTools::configuration(false));
    }
    /** Ewidencja i ustawienia zbioru w środowisku `test`; wywołujący trzyma transakcję. */
    public static function seed(Connection $db, MockClock $clock, bool $pitKasowy = false): void
    {
        $data = self::dataset();
        LedgerTestTools::importer($db, $clock)->import(LedgerTestTools::fixture('ledger-2026-jan-nov.csv'), 2026);
        $ids = [];
        foreach ($db->fetchAllAssociative("SELECT lp,id FROM revenue_entry WHERE environment='test' AND year=2026") as $row) {
            $ids[(int) (is_scalar($row['lp']) ? $row['lp'] : 0)] = $row['id'];
        }
        foreach ($data['entries'] as $entry) {
            $id = (string) UuidV7::generate($clock->now());
            $kind = $entry['kind'];
            $correctsLp = $entry['corrects_lp'] ?? null;
            $payment = null;
            if ($kind === 'fx_difference') {
                // Fikcyjna płatność dla klucza obcego `revenue_entry.payment_id` (F2.2).
                $payment = (string) UuidV7::generate($clock->now());
                $stamp = $clock->now()->format('Y-m-d\TH:i:s.uP');
                $db->insert('payment', ['id' => $payment,'environment' => 'test','freshbooks_payment_id' => 'fikcyjna-' . (is_scalar($entry['lp']) ? (string) $entry['lp'] : '0'),'paid_on' => $entry['revenue_date'],'amount' => '1.00','currency' => 'EUR','created_at' => $stamp,'updated_at' => $stamp]);
            }
            $db->insert('revenue_entry', ['id' => $id,'environment' => 'test','year' => 2026,'lp' => $entry['lp'],'entry_date' => $entry['entry_date'],'revenue_date' => $entry['revenue_date'],'document_number' => $entry['document_number'],'ksef_number' => null,'counterparty_country' => $entry['counterparty_country'],'counterparty_tax_id' => $entry['counterparty_tax_id'],'amount_pln' => $entry['amount_pln'],'rate_pct' => $entry['rate_pct'],'remarks' => $entry['remarks'],'source' => $entry['source'],'kind' => $kind,'corrects_entry_id' => is_int($correctsLp) ? $ids[$correctsLp] : null,'payment_id' => $payment,'recorded_at' => $clock->now()->format('Y-m-d\TH:i:s.uP')]);
            $ids[is_int($entry['lp']) ? $entry['lp'] : 0] = $id;
        }
        $settings = self::settings($db, $clock);
        $settings->set('company.identity', $data['company_identity'], '2026-01-01', null, 'fictional');
        $settings->set('pit.kasowy', $pitKasowy, '2026-01-01', null, 'fictional');
        $settings->set('jpk_st.enabled', false, '2026-01-01', null, 'fictional');
    }
    public static function builder(Connection $db, MockClock $clock, SecretStore $secrets): JpkEwpBuilder
    {
        $catalog = XsdCatalog::default();
        return new JpkEwpBuilder($db, $clock, self::settings($db, $clock), self::taxpayer($db, $clock, $secrets), ConnectorTestTools::configuration(false), new JpkEwpSchema(new XsdDictionary($catalog)), new XsdValidator($catalog));
    }
    public static function stBuilder(Connection $db, MockClock $clock, SecretStore $secrets): JpkStBuilder
    {
        return new JpkStBuilder($db, $clock, self::taxpayer($db, $clock, $secrets), ConnectorTestTools::configuration(false), new XsdValidator(XsdCatalog::default()));
    }
    public static function v7mBuilder(Connection $db, MockClock $clock, SecretStore $secrets): JpkV7mBuilder
    {
        return new JpkV7mBuilder($db, $clock, self::taxpayer($db, $clock, $secrets), self::settings($db, $clock), ConnectorTestTools::configuration(false), new XsdValidator(XsdCatalog::default()));
    }
    public static function taxpayer(Connection $db, MockClock $clock, SecretStore $secrets): JpkTaxpayer
    {
        return new JpkTaxpayer(self::settings($db, $clock), $secrets);
    }
    /** Fikcyjny wykaz `fixed-assets-2026.json` w środowisku `test`; zwraca liczbę wierszy `fixed_asset`. */
    public static function seedFixedAssets(Connection $db, MockClock $clock): int
    {
        $data = json_decode(LedgerTestTools::fixture('fixed-assets-2026.json'), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['fixed_assets'] ?? null)) {
            throw new \RuntimeException('Niepoprawny fikcyjny wykaz środków trwałych.');
        }
        $stamp = $clock->now()->format('Y-m-d\TH:i:s.uP');
        $count = 0;
        foreach ($data['fixed_assets'] as $asset) {
            if (!is_array($asset)) {
                throw new \RuntimeException('Niepoprawny środek trwały w fikcyjnym wykazie.');
            }
            $numbers = $asset['disposal_ksef_numbers'] ?? null;
            $db->insert('fixed_asset', [
                'id' => (string) UuidV7::generate($clock->now()), 'environment' => 'test', 'name' => $asset['name'], 'kst_code' => $asset['kst_code'], 'intangible' => $asset['intangible'] === true ? 'true' : 'false',
                'acquisition_date' => $asset['acquisition_date'], 'commissioning_date' => $asset['commissioning_date'], 'acquisition_document_number' => $asset['acquisition_document_number'],
                'acquisition_document_type' => $asset['acquisition_document_type'], 'depreciation_rate_pct' => $asset['depreciation_rate_pct'], 'initial_value_pln' => $asset['initial_value_pln'],
                'updated_value_pln' => $asset['updated_value_pln'], 'disposal_date' => $asset['disposal_date'], 'disposal_reason' => $asset['disposal_reason'], 'disposal_document_number' => $asset['disposal_document_number'],
                'disposal_ksef_numbers' => is_array($numbers) ? json_encode($numbers, JSON_THROW_ON_ERROR) : null, 'reclassified' => $asset['reclassified'] === true ? 'true' : 'false', 'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
            ++$count;
        }
        return $count;
    }
    /** Wartość XPath w JPK_ST(1); prefiks `s` to przestrzeń nazw JPK_ST, `etd` typy MF. */
    public static function stValue(string $xml, string $path): string
    {
        $xpath = self::xpath($xml);
        $xpath->registerNamespace('s', self::ST_NS);
        $value = $xpath->evaluate('string(' . $path . ')');
        return is_string($value) ? $value : '';
    }
    /** @return list<array<string,string>> wiersze `EWP/Wiersz` JPK_ST(1) jako F_1–F_16 (F_15 numery rozdzielone przecinkiem) */
    public static function stRows(string $xml): array
    {
        $xpath = self::xpath($xml);
        $xpath->registerNamespace('s', self::ST_NS);
        $rows = [];
        foreach ($xpath->query('/s:JPK/s:EWP/s:Wiersz') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $row = [];
            foreach (self::ST_FIELDS as $field) {
                $values = [];
                foreach ($xpath->query('.//s:' . $field, $node) ?: [] as $value) {
                    if ($value instanceof \DOMElement) {
                        $values[] = $value->textContent;
                    }
                }
                $row[$field] = implode(',', $values);
            }
            $rows[] = $row;
        }
        return $rows;
    }
    public static function dryRun(Connection $db, MockClock $clock, SecretStore $secrets): JpkDryRun
    {
        return new JpkDryRun($db, $clock, self::builder($db, $clock, $secrets), self::stBuilder($db, $clock, $secrets), self::settings($db, $clock), new AuditLog($db, $clock, new Redactor()), ConnectorTestTools::configuration(false));
    }
    /** @return list<array<string,string>> wiersze `EWPWiersz` pliku jako K_1–K_10 */
    public static function rows(string $xml): array
    {
        $xpath = self::xpath($xml);
        $rows = [];
        foreach ($xpath->query('/t:JPK/t:EWPWiersz') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $row = [];
            foreach (['K_1','K_2','K_3','K_4','K_5','K_6','K_7','K_8','K_9','K_10'] as $field) {
                $value = $xpath->evaluate('string(t:' . $field . ')', $node);
                $row[$field] = is_string($value) ? $value : '';
            }
            $rows[] = $row;
        }
        return $rows;
    }
    public static function xpath(string $xml): \DOMXPath
    {
        $document = new \DOMDocument();
        if (!$document->loadXML($xml, LIBXML_NONET)) {
            throw new \RuntimeException('Niepoprawny XML.');
        }
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('t', self::NS);
        $xpath->registerNamespace('etd', self::ETD);
        return $xpath;
    }
    public static function value(string $xml, string $path): string
    {
        $value = self::xpath($xml)->evaluate('string(' . $path . ')');
        return is_string($value) ? $value : '';
    }
}
