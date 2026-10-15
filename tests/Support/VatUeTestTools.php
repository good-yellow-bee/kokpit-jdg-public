<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Xml\JpkV7mBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

/**
 * Fikcyjny miesiąc VAT-UE(5) 11.2026 (F5.5, D-66): zbiór JPK_V7M z `v7m-2026-11.json` plus `vat-ue-2026-11.json`
 * (nabywca z FR, KOR do faktury `eu_b2b` z DE ze znakiem różnicy, `eu_b2b_unidentified` z AT poza plikiem). Pozycje:
 * DE 3824,53 → P_Uc 3825, FR 3393,40 → 3393; suma `net_pln` 7217,93 = K_12. Wywołujący trzyma transakcję.
 */
final class VatUeTestTools
{
    public const PERIOD = JpkV7mTestTools::PERIOD;

    /** @return array<mixed> */
    public static function dataset(): array
    {
        $data = json_decode(LedgerTestTools::fixture('vat-ue-2026-11.json'), true, 16, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : throw new \RuntimeException('Niepoprawny fikcyjny miesiąc VAT-UE.');
    }

    /** @return array<string,mixed> */
    public static function expected(): array
    {
        $expected = self::dataset()['expected'];
        return is_array($expected) ? array_filter($expected, is_string(...), ARRAY_FILTER_USE_KEY) : [];
    }

    /**
     * Zbiór JPK_V7M (bez bilansu otwarcia) i dodatki VAT-UE.
     *
     * @return array{invoices:array<string,string>,counterparties:array<string,string>,fx:array<string,string>}
     */
    public static function seed(Connection $db, MockClock $clock): array
    {
        $seeded = JpkV7mTestTools::seed($db, $clock);
        $data = self::dataset();
        $stamp = $clock->now()->format('Y-m-d\TH:i:s.uP');
        foreach (self::map($data['counterparties']) as $key => $party) {
            $id = (string) UuidV7::generate($clock->now());
            $db->insert('counterparty', ['id' => $id, 'environment' => 'test', 'name' => $party['name'], 'country' => $party['country'], 'eu_vat_prefix' => $party['eu_vat_prefix'] ?? null,
                'eu_vat_number' => $party['eu_vat_number'] ?? null, 'address' => '{}', 'bank_accounts' => '[]', 'is_client' => 'true', 'is_supplier' => 'false', 'is_natural_person' => 'false', 'created_at' => $stamp, 'updated_at' => $stamp]);
            $seeded['counterparties'][$key] = $id;
        }
        foreach (self::map($data['verifications']) as $check) {
            $db->insert('verification', ['id' => (string) UuidV7::generate($clock->now()), 'environment' => 'test', 'counterparty_id' => $seeded['counterparties'][self::text($check['counterparty'])],
                'source' => $check['source'], 'subject' => $check['subject'], 'checked_for_date' => $check['checked_for_date'], 'checked_at' => $stamp, 'result' => $check['result']]);
        }
        foreach (self::map($data['sales']) as $sale) {
            $seeded['invoices'][self::text($sale['number'])] = self::sale($db, $clock, $sale, $seeded);
        }
        return $seeded;
    }

    /**
     * Fikcyjna faktura sprzedaży z `vat-ue-2026-11.json` (np. `late_kor`) w okresie VAT 11.2026.
     *
     * @param array<mixed> $sale
     * @param array{invoices:array<string,string>,counterparties:array<string,string>,fx:array<string,string>} $seeded
     */
    public static function sale(Connection $db, MockClock $clock, array $sale, array $seeded): string
    {
        $gross = (string) \Brick\Math\BigDecimal::of(self::text($sale['net']))->plus(self::text($sale['vat']));
        $corrects = is_string($sale['corrects'] ?? null) ? $seeded['invoices'][$sale['corrects']] : null;
        return JpkV7mTestTools::invoice($db, $clock, ['direction' => 'sale', 'status' => 'accepted', 'kind' => $sale['kind'], 'tax_scenario' => $sale['scenario'], 'invoice_number' => $sale['number'],
            'counterparty_id' => $seeded['counterparties'][self::text($sale['counterparty'])], 'currency' => $sale['currency'], 'issue_date' => $sale['issue_date'], 'tax_point_date' => $sale['tax_point_date'],
            'vat_period_start' => self::PERIOD, 'net_total' => $sale['net'], 'vat_total' => $sale['vat'], 'gross_total' => $gross, 'fx_vat_rate_id' => $sale['fx'] === null ? null : $seeded['fx'][self::text($sale['fx'])],
            'fx_pit_rate_id' => $sale['fx'] === null ? null : $seeded['fx'][self::text($sale['fx'])], 'net_pln' => $sale['net_pln'], 'vat_pln' => $sale['vat_pln'], 'ksef_number' => $sale['ksef_number'],
            'ksef_issue_mode' => $sale['ksef_issue_mode'], 'ksef_number_assigned_at' => self::text($sale['issue_date']) . 'T12:00:00Z', 'corrects_invoice_id' => $corrects,
            'corrected_invoice_number' => $sale['corrects'] ?? null, 'correction_type' => $sale['correction_type'] ?? null, 'correction_reason' => $corrects === null ? null : 'Rabat (fictional)']);
    }

    /**
     * Złożenie JPK_V7M za 11.2026 z `filing.totals` z prawdziwego `JpkV7mBuilder` (K_12 z bieżącego stanu faktur).
     * Istniejące złożenie `purpose` 1 dostaje nowe sumy; `$k12` nadpisuje K_12 (scenariusz niezgodności).
     */
    public static function v7mFiling(Connection $db, MockClock $clock, JpkV7mBuilder $builder, string $status = 'accepted', ?string $k12 = null): string
    {
        $document = $builder->build(self::PERIOD);
        $totals = $document->totals;
        if ($k12 !== null) {
            $totals['k12'] = $k12;
        }
        $json = json_encode($totals, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $stamp = $clock->now()->format('Y-m-d\TH:i:s.uP');
        $existing = $db->fetchOne("SELECT id FROM filing WHERE environment='test' AND type='JPK_V7M' AND period_start=? AND purpose=1", [self::PERIOD]);
        if (is_string($existing)) {
            $db->update('filing', ['totals' => $json, 'status' => $status, 'updated_at' => $stamp], ['id' => $existing]);
            return $existing;
        }
        $id = (string) UuidV7::generate($clock->now());
        $db->insert('filing', ['id' => $id, 'environment' => 'test', 'type' => 'JPK_V7M', 'period_start' => self::PERIOD, 'period_end' => '2026-11-30', 'purpose' => 1, 'seq' => 0, 'status' => $status,
            'schema_version' => JpkV7mBuilder::SCHEMA_VERSION, 'totals' => $json, 'submitted_via' => $status === 'accepted' ? 'jpk' : null, 'created_at' => $stamp, 'updated_at' => $stamp]);
        return $id;
    }

    /** Otwarty obowiązek `vat_ue` za 11.2026 (termin 25.12, przesunięty na 28.12). */
    public static function obligation(Connection $db, MockClock $clock): string
    {
        $id = (string) UuidV7::generate($clock->now());
        $stamp = $clock->now()->format('Y-m-d\TH:i:s.uP');
        $db->insert('obligation', ['id' => $id, 'environment' => 'test', 'type' => 'vat_ue', 'rule_key' => 'vat_ue.monthly', 'period_start' => self::PERIOD, 'nominal_due_date' => '2026-12-25',
            'due_date' => '2026-12-28', 'status' => 'open', 'dedupe_key' => 'vat_ue:' . self::PERIOD, 'created_at' => $stamp, 'updated_at' => $stamp]);
        return $id;
    }

    /**
     * Pola `Grupa3` deklaracji VAT-UE albo VAT-UEK w kolejności pliku.
     *
     * @return list<array<string,string>>
     */
    public static function groups(string $xml): array
    {
        $document = new \DOMDocument();
        if (!$document->loadXML($xml, LIBXML_NONET) || $document->documentElement === null) {
            throw new \RuntimeException('Niepoprawny XML.');
        }
        $groups = [];
        foreach ($document->getElementsByTagNameNS((string) $document->documentElement->namespaceURI, 'Grupa3') as $group) {
            $fields = [];
            foreach ($group->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    $fields[$child->localName] = $child->textContent;
                }
            }
            $groups[] = $fields;
        }
        return $groups;
    }

    /** @return array<array<mixed>> */
    private static function map(mixed $list): array
    {
        if (!is_array($list)) {
            throw new \RuntimeException('Niepoprawna lista w fikcyjnym miesiącu VAT-UE.');
        }
        return array_filter($list, is_array(...));
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : throw new \RuntimeException('Niepoprawna wartość w fikcyjnym miesiącu VAT-UE.');
    }
}
