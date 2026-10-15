<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Ledger\OpeningBalance;
use App\Setting\SettingKeys;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/**
 * Fikcyjny miesiąc JPK_V7M(3) 11.2026 (F5.4, D-65) z `v7m-2026-11.json`: kontrahenci z PL, UE i spoza UE, kursy NBP,
 * sprzedaż np I, np II i 23%, import usług z UE i spoza UE, dowód `WEW`, faktury `NrKSeF`, `BFK` i `DI`. Wywołujący
 * trzyma transakcję; wszystkie dane są fikcyjne.
 */
final class JpkV7mTestTools
{
    public const PERIOD = '2026-11-01';
    public const NOW = '2026-12-10T09:00:00Z';

    /** @return array<mixed> */
    public static function dataset(): array
    {
        $data = json_decode(LedgerTestTools::fixture('v7m-2026-11.json'), true, 16, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : throw new \RuntimeException('Niepoprawny fikcyjny miesiąc JPK_V7M.');
    }

    /** @return array<string,string> oczekiwane pola deklaracji złotego miesiąca */
    public static function expectedDeclaration(): array
    {
        $expected = self::dataset()['expected'];
        return is_array($expected) && is_array($expected['declaration']) ? array_map(self::text(...), $expected['declaration']) : [];
    }

    /**
     * Zbiór w środowisku `test`: `company.identity` z fikcyjnego zbioru JPK, kontrahenci, kursy, VIES, faktury; przy
     * `$opening` także `opening.vat_carry_forward_pln` z `valid_from` = 2026-11-01 zwykłym zapisem (przed potwierdzeniem).
     *
     * @return array{invoices:array<string,string>,counterparties:array<string,string>,fx:array<string,string>}
     */
    public static function seed(Connection $db, MockClock $clock, bool $opening = true): array
    {
        $data = self::dataset();
        $stamp = $clock->now()->format('Y-m-d\TH:i:s.uP');
        $settings = JpkTestTools::settings($db, $clock);
        $settings->set(SettingKeys::COMPANY_IDENTITY, JpkTestTools::dataset()['company_identity'], '2026-01-01', null, 'fictional');
        if ($opening) {
            $settings->set(SettingKeys::OPENING_VAT_CARRY_FORWARD, self::text($data['opening_vat_carry_forward_pln']), self::PERIOD, null, 'fictional');
        }
        $fx = [];
        foreach (self::rows($data['fx_rates']) as $rate) {
            $id = (string) UuidV7::generate($clock->now());
            $db->insert('fx_rate', ['id' => $id, 'source' => 'nbp', 'table_no' => $rate['table_no'], 'currency' => $rate['currency'], 'effective_date' => $rate['effective_date'], 'mid' => $rate['mid'], 'fetched_at' => $stamp]);
            $fx[self::text($rate['ref'])] = $id;
        }
        $parties = [];
        foreach (is_array($data['counterparties']) ? $data['counterparties'] : [] as $key => $party) {
            if (!is_array($party)) {
                continue;
            }
            $id = (string) UuidV7::generate($clock->now());
            $db->insert('counterparty', ['id' => $id, 'environment' => 'test', 'name' => $party['name'], 'country' => $party['country'], 'nip' => $party['nip'] ?? null,
                'eu_vat_prefix' => $party['eu_vat_prefix'] ?? null, 'eu_vat_number' => $party['eu_vat_number'] ?? null, 'foreign_tax_id' => $party['foreign_tax_id'] ?? null,
                'address' => '{}', 'bank_accounts' => '[]', 'is_client' => ($party['is_client'] ?? false) === true ? 'true' : 'false', 'is_supplier' => ($party['is_supplier'] ?? false) === true ? 'true' : 'false',
                'is_natural_person' => 'false', 'created_at' => $stamp, 'updated_at' => $stamp]);
            $parties[(string) $key] = $id;
        }
        foreach (self::rows($data['verifications']) as $check) {
            $db->insert('verification', ['id' => (string) UuidV7::generate($clock->now()), 'environment' => 'test', 'counterparty_id' => $parties[self::text($check['counterparty'])], 'source' => $check['source'],
                'subject' => $check['subject'], 'checked_for_date' => $check['checked_for_date'], 'checked_at' => $stamp, 'result' => $check['result']]);
        }
        $invoices = [];
        foreach (self::rows($data['sales']) as $sale) {
            $invoices[self::text($sale['number'])] = self::invoice($db, $clock, ['direction' => 'sale', 'status' => 'accepted', 'tax_scenario' => $sale['scenario'], 'ksef_number' => $sale['ksef_number'],
                'ksef_issue_mode' => $sale['ksef_issue_mode'], 'ksef_number_assigned_at' => self::text($sale['issue_date']) . 'T12:00:00Z'] + self::common($sale, $parties, $fx, true));
        }
        foreach (self::rows($data['costs']) as $cost) {
            $invoices[self::text($cost['number'])] = self::invoice($db, $clock, ['direction' => 'cost', 'cost_status' => 'assigned', 'cost_review' => 'business', 'source' => $cost['source'],
                'ksef_number' => $cost['ksef_number'] ?? null, 'ksef_issue_mode' => $cost['ksef_issue_mode'], 'ksef_number_assigned_at' => $cost['ksef_number_assigned_at'] ?? null, 'received_on' => $cost['received_on'] ?? null,
                'import_services' => $cost['import_services'] ?? null, 'self_assessed_vat_pln' => $cost['self_assessed_vat_pln'] ?? null, 'jpk_doc_type' => $cost['jpk_doc_type'] ?? null,
                'vat_exempt' => $cost['vat_exempt'] === true ? 'true' : 'false', 'deductible' => $cost['deductible'] === true ? 'true' : 'false', 'is_fixed_asset' => 'false'] + self::common($cost, $parties, $fx, false));
        }
        return ['invoices' => $invoices, 'counterparties' => $parties, 'fx' => $fx];
    }

    /**
     * Fikcyjna faktura z kwotami w PLN po kursie VAT; `$fields` nadpisuje kolumny.
     *
     * @param array<string,mixed> $fields
     */
    public static function invoice(Connection $db, MockClock $clock, array $fields): string
    {
        $stamp = $clock->now()->format('Y-m-d\TH:i:s.uP');
        $id = (string) UuidV7::generate($clock->now());
        $db->insert('invoice', ['id' => $id, 'environment' => 'test', 'kind' => 'VAT', 'version' => 1, 'created_at' => $stamp, 'updated_at' => $stamp] + $fields);
        return $id;
    }

    /**
     * Potwierdzenie bilansu otwarcia 11.2026 przez `OpeningBalance` (formularz „Otwarcie” bez panelu) z fikcyjnymi dowodami.
     *
     * @return array<string,string>
     */
    public static function confirmOpening(JpkSendTestTools $send, Connection $db, string $vatCarryForward = '150.00'): array
    {
        $settings = JpkTestTools::settings($db, $send->clock);
        $opening = new OpeningBalance($db, $send->audit, $send->files, $settings, ConnectorTestTools::configuration(false));
        $evidence = array_map(static fn(string $field): array => ['bytes' => "%PDF-1.4\n% fikcyjny dowod " . $field . " (fictional)\n", 'media_type' => 'application/pdf'], OpeningBalance::EVIDENCE);
        return $opening->confirm($send->userId, 'fictional-step-up-credential', self::PERIOD, self::openingValues($vatCarryForward), $evidence);
    }

    /** @return array<string,mixed> fikcyjne wartości kroków 2–5 bilansu otwarcia */
    public static function openingValues(string $vatCarryForward = '150.00'): array
    {
        return [
            SettingKeys::OPENING_VAT_CARRY_FORWARD => $vatCarryForward,
            SettingKeys::OPENING_REVENUE_YTD => ['12.00' => '84000.00'],
            SettingKeys::OPENING_ZUS_PAID_YTD => ['social_pln' => '15000.00', 'health_pln' => '4200.00'],
            SettingKeys::OPENING_LAST_INVOICE_NUMBERS => ['FV' => 'FV/2026/10/12'],
        ];
    }

    /** Ważne poświadczenie `auth_data` (bez szyfrogramu) dla `checkDecision`. */
    public static function credential(Connection $db, MockClock $clock): void
    {
        $stamp = $clock->now()->format('Y-m-d\TH:i:s.uP');
        $db->insert('credential_state', ['id' => (string) UuidV7::generate($clock->now()), 'environment' => 'test', 'kind' => 'auth_data', 'status' => 'valid',
            'valid_until' => $clock->now()->modify('+400 days')->format('Y-m-d\TH:i:s.uP'), 'created_at' => $stamp, 'updated_at' => $stamp]);
    }

    /** Wartość XPath w JPK_V7M(3); prefiks `v` to przestrzeń nazw JPK_V7M. */
    public static function value(string $xml, string $path): string
    {
        $xpath = self::xpath($xml);
        $value = $xpath->evaluate('string(' . $path . ')');
        return is_string($value) ? $value : '';
    }

    /** @return list<array<string,string>> wiersze rejestru (`SprzedazWiersz` albo `ZakupWiersz`): element → wartość */
    public static function register(string $xml, string $register): array
    {
        $xpath = self::xpath($xml);
        $rows = [];
        foreach ($xpath->query('/v:JPK/v:Ewidencja/v:' . $register) ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $row = [];
            foreach ($node->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    $row[$child->localName] = $child->textContent;
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** @return array<string,string> pola `PozycjeSzczegolowe` deklaracji w kolejności pliku */
    public static function declaration(string $xml): array
    {
        $xpath = self::xpath($xml);
        $fields = [];
        foreach ($xpath->query('/v:JPK/v:Deklaracja/v:PozycjeSzczegolowe/*') ?: [] as $node) {
            if ($node instanceof \DOMElement) {
                $fields[$node->localName] = $node->textContent;
            }
        }
        return $fields;
    }

    private static function xpath(string $xml): \DOMXPath
    {
        $document = new \DOMDocument();
        if (!$document->loadXML($xml, LIBXML_NONET)) {
            throw new \RuntimeException('Niepoprawny XML.');
        }
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('v', 'http://crd.gov.pl/wzor/2025/12/19/14090/');
        return $xpath;
    }

    /**
     * @param array<mixed> $row
     * @param array<string,string> $parties
     * @param array<string,string> $fx
     * @return array<string,mixed>
     */
    private static function common(array $row, array $parties, array $fx, bool $sale): array
    {
        $rate = $row['fx'] === null ? null : $fx[self::text($row['fx'])];
        $gross = (string) \Brick\Math\BigDecimal::of(self::text($row['net']))->plus(self::text($row['vat']));
        return ['invoice_number' => $row['number'], 'counterparty_id' => $parties[self::text($row['counterparty'])], 'currency' => $row['currency'], 'issue_date' => $row['issue_date'],
            'tax_point_date' => $row['tax_point_date'], 'vat_period_start' => self::PERIOD, 'net_total' => $row['net'], 'vat_total' => $row['vat'], 'gross_total' => $gross,
            'fx_vat_rate_id' => $rate, 'fx_pit_rate_id' => $sale ? $rate : null, 'net_pln' => $row['net_pln'], 'vat_pln' => $row['vat_pln']];
    }

    /** @return list<array<mixed>> */
    private static function rows(mixed $list): array
    {
        if (!is_array($list)) {
            throw new \RuntimeException('Niepoprawna lista w fikcyjnym miesiącu.');
        }
        return array_values(array_filter($list, is_array(...)));
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : throw new \RuntimeException('Niepoprawna wartość w fikcyjnym miesiącu.');
    }
}
