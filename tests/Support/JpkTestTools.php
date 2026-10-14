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
use App\Xml\XsdCatalog;
use App\Xml\XsdDictionary;
use App\Xml\XsdValidator;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/** Fikcyjny zbiór dry runu F2.4: import `ledger-2026-jan-nov.csv`, wiersze z `jpk-2026-dry-run.json` i ustawienia firmy. */
final class JpkTestTools
{
    public const NS = 'http://jpk.mf.gov.pl/wzor/2024/10/30/10301/';
    public const ETD = 'http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2022/01/05/eD/DefinicjeTypy/';
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
            $db->insert('revenue_entry', ['id' => $id,'environment' => 'test','year' => 2026,'lp' => $entry['lp'],'entry_date' => $entry['entry_date'],'revenue_date' => $entry['revenue_date'],'document_number' => $entry['document_number'],'ksef_number' => null,'counterparty_country' => $entry['counterparty_country'],'counterparty_tax_id' => $entry['counterparty_tax_id'],'amount_pln' => $entry['amount_pln'],'rate_pct' => $entry['rate_pct'],'remarks' => $entry['remarks'],'source' => $entry['source'],'kind' => $kind,'corrects_entry_id' => is_int($correctsLp) ? $ids[$correctsLp] : null,'payment_id' => $kind === 'fx_difference' ? (string) UuidV7::generate($clock->now()) : null,'recorded_at' => $clock->now()->format('Y-m-d\TH:i:s.uP')]);
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
        return new JpkEwpBuilder($db, $clock, self::settings($db, $clock), $secrets, ConnectorTestTools::configuration(false), new JpkEwpSchema(new XsdDictionary($catalog)), new XsdValidator($catalog));
    }
    public static function dryRun(Connection $db, MockClock $clock, SecretStore $secrets): JpkDryRun
    {
        return new JpkDryRun($db, $clock, self::builder($db, $clock, $secrets), new JpkStBuilder(XsdCatalog::default()), self::settings($db, $clock), new AuditLog($db, $clock, new Redactor()), ConnectorTestTools::configuration(false));
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
