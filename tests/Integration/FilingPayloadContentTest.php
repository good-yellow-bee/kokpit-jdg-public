<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\JpkSendTestTools;
use App\Tests\Support\JpkTestTools;
use App\Tests\Support\JpkV7mTestTools;
use App\Tests\Support\Pit28TestTools;
use App\Tests\Support\VatUeTestTools;
use Symfony\Component\Uid\UuidV7;

/**
 * Pełna treść prośby `filing` dla każdego typu złożenia: `approval.summary`, `approval.preconditions`, `filing.totals`
 * i `filing.schema_version` po budowie. Szkic z nieaktualnym `schema_version` dostaje wersję generatora, a inne złożenia
 * zostają bez zmian. Dane fikcyjne, bez sieci.
 */
final class FilingPayloadContentTest extends VatUeTestCase
{
    private const OTHER_TOTALS = ['fictional' => true];

    public function testJpkEwpAndJpkStCarryFullSummaryPreconditionsAndTotals(): void
    {
        $send = $this->send();
        $this->filingRow($send, 'JPK_EWP', '2026-01-01', '2026-12-31', 'JPK_EWP(3)');
        $other = $this->filingRow($send, 'VAT_UE', '2026-10-01', '2026-10-31', 'VAT-UE(5)', self::OTHER_TOTALS);
        // Rok 2027 nie wchodzi do bramek 2026: zamknięty okres, sprzedaż w toku i sprzedaż w walucie bez kursu PIT.
        $this->nextYear($send);

        $approvalId = $send->request->jpkEwp(JpkSendTestTools::YEAR);
        $payload = $send->payload($approvalId);
        $sumByRate = [12 => '38860.00', '8.5' => '8121.50'];
        $checklist = ['ledger_closed' => ['ok' => true, 'count' => 0], 'pit_fx_rates' => ['ok' => true, 'count' => 0], 'sales_final' => ['ok' => true, 'count' => 0]];
        self::assertSame(self::sorted(['type' => 'JPK_EWP', 'period' => '2026', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'purpose' => 1, 'schema_version' => 'JPK_EWP(4)',
            'rows' => 19, 'sum_k8' => '46981.50', 'sum_by_rate' => $sumByRate, 'revenue_entry_sum' => '46981.50', 'checklist' => $checklist]), $this->json($send->approval($approvalId)['summary']));
        self::assertSame(self::sorted(['xsd' => 'ok (LIBXML_NONET)', 'connector_target' => 'test', 'environment' => 'test', 'ledger_closed' => true, 'pit_fx_rates' => true, 'sales_final' => true,
            'k8_equals_ledger' => true]), $this->json($send->approval($approvalId)['preconditions']));
        $filing = $send->filing('JPK_EWP');
        self::assertSame('JPK_EWP(4)', $filing['schema_version']);
        self::assertSame(self::sorted(['rows' => 19, 'sum' => '46981.50', 'sum_by_rate' => $sumByRate, 'xml_sha256' => hash('sha256', $payload), 'revenue_entry_sum' => '46981.50']), $this->json($filing['totals']));
        $this->assertUntouched($send, $other);

        JpkTestTools::seedFixedAssets($this->db(), $send->clock);
        JpkTestTools::settings($this->db(), $send->clock)->set('jpk_st.enabled', true, '2026-12-01', null, 'fictional');
        $approvalId = $send->request->jpkSt(JpkSendTestTools::YEAR);
        $payload = $send->payload($approvalId);
        self::assertSame(self::sorted(['type' => 'JPK_ST', 'period' => '2026', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'purpose' => 1, 'schema_version' => 'JPK_ST(1)',
            'rows' => 4, 'sum_f10' => '20500.00', 'fixed_asset_count' => 6, 'checklist' => ['jpk_st_enabled' => ['ok' => true, 'count' => 0]]]), $this->json($send->approval($approvalId)['summary']));
        self::assertSame(self::sorted(['xsd' => 'ok (LIBXML_NONET)', 'connector_target' => 'test', 'environment' => 'test', 'jpk_st_enabled' => true]), $this->json($send->approval($approvalId)['preconditions']));
        self::assertSame(self::sorted(['rows' => 4, 'sum' => '20500.00', 'sum_by_rate' => [], 'xml_sha256' => hash('sha256', $payload)]), $this->json($send->filing('JPK_ST')['totals']));
        $this->assertUntouched($send, $other);
    }

    public function testJpkV7mAndCorrectionCarryFullSummaryPreconditionsAndTotals(): void
    {
        $send = $this->v7m(opening: false);
        JpkV7mTestTools::confirmOpening($send, $this->db());
        $this->filingRow($send, 'JPK_V7M', JpkV7mTestTools::PERIOD, '2026-11-30', 'JPK_V7M(2)');
        $first = $send->request->jpkV7m(JpkV7mTestTools::PERIOD);
        $payload = $send->payload($first);
        $totals = self::v7mTotals() + ['xml_sha256' => hash('sha256', $payload)];
        $head = ['type' => 'JPK_V7M', 'period' => '2026-11', 'period_start' => JpkV7mTestTools::PERIOD, 'period_end' => '2026-11-30', 'schema_version' => 'JPK_V7M(3)'];
        $gates = array_fill_keys(['sales_final', 'costs_reviewed', 'fx_rates', 'vies_eu_b2b', 'eu_b2b_unidentified', 'opening_balance'], ['ok' => true, 'count' => 0]);
        $tail = ['declaration' => self::v7mDeclaration(), 'di_items' => self::diItems(), 'checklist' => $gates];
        self::assertSame(self::sorted($head + ['purpose' => 1] + $totals + $tail), $this->json($send->approval($first)['summary']));
        $preconditions = self::sorted(['xsd' => 'ok (LIBXML_NONET)', 'connector_target' => 'test', 'environment' => 'test', 'gates' => 'ok', 'p39_source' => 'opening']);
        self::assertSame($preconditions, $this->json($send->approval($first)['preconditions']));
        $original = $send->filing('JPK_V7M', JpkV7mTestTools::PERIOD);
        self::assertSame('JPK_V7M(3)', $original['schema_version']);
        self::assertSame(self::sorted($totals), $this->json($original['totals']));

        $send->gate->approve($first, $send->userId);
        $send->loadUpload();
        $send->fake->load('api-status-200');
        self::assertSame('submitted', $send->handler->dispatch($first));
        self::assertSame(1, $send->reconciler->poll());
        $accepted = $send->filing('JPK_V7M', JpkV7mTestTools::PERIOD);
        $send->periods->reopen(JpkV7mTestTools::PERIOD, 'Fikcyjny powód ponownego otwarcia', $send->userId, 'fictional-step-up-credential');
        $reason = 'Fikcyjna korekta: zażółć/gęślą (fictional)';
        $second = $send->request->jpkV7mCorrection(JpkV7mTestTools::PERIOD, $reason);
        $payload = $send->payload($second);
        $totals = self::v7mTotals() + ['p_ordzu' => $reason, 'xml_sha256' => hash('sha256', $payload)];
        $declaration = self::v7mDeclaration() + ['P_ORDZU' => $reason];
        $previous = ['filing_id' => $accepted['id'], 'p51' => '12', 'p62' => '0'];
        self::assertSame(self::sorted($head + ['purpose' => 2] + $totals + ['declaration' => $declaration, 'previous' => $previous] + $tail), $this->json($send->approval($second)['summary']));
        self::assertSame($preconditions, $this->json($send->approval($second)['preconditions']));
        self::assertSame(self::sorted($totals), $this->json($send->filing('JPK_V7M', JpkV7mTestTools::PERIOD, 1)['totals']));
        // Złożenie zastępowane zachowuje swoje sumy.
        self::assertSame($accepted['totals'], $send->filing('JPK_V7M', JpkV7mTestTools::PERIOD)['totals']);
    }

    public function testVatUeAndVatUekCarryFullSummaryPreconditionsAndTotals(): void
    {
        $send = $this->vatUe('accepted');
        $v7m = $send->filing('JPK_V7M', VatUeTestTools::PERIOD);
        $this->filingRow($send, 'VAT_UE', VatUeTestTools::PERIOD, '2026-11-30', 'VAT-UE(4)');
        $first = $send->request->vatUe(VatUeTestTools::PERIOD);
        $payload = $send->payload($first);
        $positions = [self::position('DE', '999999999', '3824.53', 3825, 2), self::position('FR', '99999999999', '3393.40', 3393, 1)];
        $gate = ['k12' => '7217.93', 'net_pln_sum' => '7217.93', 'p_uc_sum' => 7218, 'difference' => '0.00', 'rounding_difference' => '0.07', 'tolerance' => '1.00', 'v7m_filing_id' => $v7m['id']];
        $totals = $gate + ['buyers' => 2, 'invoices' => 3, 'excluded_unidentified' => 1, 'positions' => $positions];
        $checklist = ['checklist' => ['k12' => ['ok' => true, 'count' => 0]]];
        $preconditions = self::sorted(['xsd' => 'ok (LIBXML_NONET)', 'connector_target' => 'test', 'environment' => 'test', 'k12_gate' => 'ok', 'auth_data' => 'DaneAutoryzujace przy wysyłce, poza xml_sent']);
        self::assertSame(
            self::sorted(['type' => 'VAT_UE', 'period' => '2026-11', 'period_start' => VatUeTestTools::PERIOD, 'period_end' => '2026-11-30', 'purpose' => 1, 'schema_version' => 'VAT-UE(5)'] + $totals + $checklist),
            $this->json($send->approval($first)['summary']),
        );
        self::assertSame($preconditions, $this->json($send->approval($first)['preconditions']));
        $filing = $send->filing('VAT_UE', VatUeTestTools::PERIOD);
        self::assertSame('VAT-UE(5)', $filing['schema_version']);
        self::assertSame(self::sorted($totals + ['xml_sha256' => hash('sha256', $payload)]), $this->json($filing['totals']));
        self::assertSame($v7m['totals'], $send->filing('JPK_V7M', VatUeTestTools::PERIOD)['totals']);

        $send->gate->approve($first, $send->userId);
        $send->edeklaracje->load('soap-send', 'soap-upo-200');
        self::assertSame('submitted', $send->handler->dispatch($first));
        self::assertSame(1, $send->reconciler->poll());
        $this->lateKor($send);
        $v7m = $send->filing('JPK_V7M', VatUeTestTools::PERIOD);
        $second = $send->request->vatUe(VatUeTestTools::PERIOD);
        $payload = $send->payload($second);
        $after = [self::position('DE', '999999999', '3824.53', 3825, 2), self::position('FR', '99999999999', '3181.40', 3181, 2)];
        $totals = ['k12' => '7005.93', 'net_pln_sum' => '7005.93', 'p_uc_sum' => 7006] + $gate + ['buyers' => 2, 'invoices' => 4, 'excluded_unidentified' => 1, 'positions' => $after, 'before' => $positions];
        self::assertSame(self::sorted(['type' => 'VAT_UEK', 'period' => '2026-11', 'period_start' => VatUeTestTools::PERIOD, 'period_end' => '2026-11-30', 'purpose' => 2, 'schema_version' => 'VAT-UEK(5)'] + $totals + $checklist
            + ['previous' => ['filing_id' => $filing['id'], 'type' => 'VAT_UE']]), $this->json($send->approval($second)['summary']));
        self::assertSame($preconditions, $this->json($send->approval($second)['preconditions']));
        $correction = $send->filing('VAT_UEK', VatUeTestTools::PERIOD, 1);
        self::assertSame(['VAT-UEK(5)', 2], [$correction['schema_version'], $correction['purpose']]);
        self::assertSame(self::sorted($totals + ['xml_sha256' => hash('sha256', $payload)]), $this->json($correction['totals']));
        self::assertSame($v7m['totals'], $send->filing('JPK_V7M', VatUeTestTools::PERIOD)['totals']);
    }

    public function testPit28CarriesFullSummaryPreconditionsAndTotals(): void
    {
        $send = new JpkSendTestTools($this->db(), $this->secrets(), Pit28TestTools::NOW, ['jpk.init_upload' => 10, 'edeklaracje.send' => 10]);
        $seeded = Pit28TestTools::seed($this->db(), $send->clock, $send->userId);
        $ewp = $send->filing('JPK_EWP', '2025-01-01');
        $approvalId = $send->request->pit28(Pit28TestTools::YEAR);
        $payload = $send->payload($approvalId);
        $advances = array_combine(array_map(static fn(int $month): string => sprintf('2025-%02d-01', $month), range(1, 12)), ['1558', '1558', '1558', '1558', '1764', '1564', '1557', '1557', '1760', '1566', '1558', '1558']);
        $totals = ['year' => 2025, 'revenue' => ['8.50' => '4801.00', '12.00' => '180000.00'], 'revenue_total' => '184801.00', 'social_paid' => '19203.84', 'social_deducted' => '19203.84', 'health_paid' => '9966.96',
            'health_deductible' => '4983.48', 'health_deducted' => '4983.48', 'base' => '160613', 'ryczalt' => '19127.54', 'ryczalt_due' => '19128', 'advances_paid' => '17558.00', 'advances_computed' => $advances,
            'to_pay' => '1570', 'overpaid' => '0'];
        $gates = ['ledger_closed' => ['ok' => true, 'count' => 0], 'pit_fx_rates' => ['ok' => true, 'count' => 0], 'sales_final' => ['ok' => true, 'count' => 0], 'jpk_ewp_sum' => ['ok' => true, 'count' => 0]];
        self::assertSame(
            self::sorted(['type' => 'PIT_28', 'period' => '2025', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31', 'purpose' => 1, 'schema_version' => 'PIT-28(27)'] + $totals + ['checklist' => $gates]),
            $this->json($send->approval($approvalId)['summary']),
        );
        self::assertSame(self::sorted(['xsd' => 'ok (LIBXML_NONET)', 'connector_target' => 'test', 'environment' => 'test', 'jpk_ewp_sum' => 'ok', 'adviser_review' => 'cel testowy',
            'auth_data' => 'DaneAutoryzujace przy wysyłce, poza xml_sent']), $this->json($send->approval($approvalId)['preconditions']));
        $filing = $send->filing('PIT_28', '2025-01-01');
        self::assertSame('PIT-28(27)', $filing['schema_version']);
        self::assertSame(self::sorted($totals + ['jpk_ewp_filing_id' => $seeded['ewp'], 'xml_sha256' => hash('sha256', $payload)]), $this->json($filing['totals']));
        self::assertSame($ewp['totals'], $send->filing('JPK_EWP', '2025-01-01')['totals']);
    }

    /** @param array<string,mixed> $totals */
    private function filingRow(JpkSendTestTools $send, string $type, string $start, string $end, string $schema, array $totals = []): string
    {
        $id = (string) UuidV7::generate($send->clock->now());
        $stamp = $send->clock->now()->format('Y-m-d\TH:i:s.uP');
        $this->db()->insert('filing', ['id' => $id, 'environment' => 'test', 'type' => $type, 'period_start' => $start, 'period_end' => $end, 'purpose' => 1, 'seq' => 0, 'status' => 'draft',
            'schema_version' => $schema, 'totals' => json_encode((object) $totals, JSON_THROW_ON_ERROR), 'created_at' => $stamp, 'updated_at' => $stamp]);
        return $id;
    }

    private function assertUntouched(JpkSendTestTools $send, string $id): void
    {
        self::assertSame(['draft', 'VAT-UE(5)', self::OTHER_TOTALS], array_values(array_map(
            fn(mixed $value): mixed => is_string($value) && str_starts_with($value, '{') ? $this->json($value) : $value,
            (array) $this->db()->fetchAssociative('SELECT status, schema_version, totals FROM filing WHERE id=?', [$id]),
        )));
    }

    private function nextYear(JpkSendTestTools $send): void
    {
        $stamp = $send->clock->now()->format('Y-m-d\TH:i:s.uP');
        $this->db()->insert('period', ['id' => (string) UuidV7::generate($send->clock->now()), 'environment' => 'test', 'period_start' => '2027-01-01', 'status' => 'open', 'ledger_closed_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp]);
        $counterparty = (string) UuidV7::generate($send->clock->now());
        $this->db()->insert('counterparty', ['id' => $counterparty, 'environment' => 'test', 'name' => 'Fikcyjny kontrahent 2027 (fictional)', 'country' => 'DE', 'address' => '{}', 'is_client' => 'true', 'is_supplier' => 'false',
            'is_natural_person' => 'false', 'bank_accounts' => '[]', 'created_at' => $stamp, 'updated_at' => $stamp]);
        foreach ([['EUR', 'accepted'], ['PLN', 'submitted']] as $n => [$currency, $status]) {
            $this->db()->insert('invoice', ['id' => (string) UuidV7::generate($send->clock->now()), 'environment' => 'test', 'direction' => 'sale', 'kind' => 'VAT', 'invoice_number' => 'FICTIONAL/2027/01/' . $n,
                'counterparty_id' => $counterparty, 'currency' => $currency, 'issue_date' => '2027-01-05', 'revenue_date' => '2027-01-05', 'net_total' => '100.00', 'vat_total' => '0.00', 'gross_total' => '100.00',
                'status' => $status, 'created_at' => $stamp, 'updated_at' => $stamp]);
        }
    }

    /** @return array<string,mixed> */
    private static function v7mTotals(): array
    {
        return ['k11' => '6150.00', 'k12' => '4250.00', 'p38' => '602', 'p39' => '150', 'p48' => '590', 'p51' => '12', 'p53' => '0', 'p62' => '0', 'vat_due' => '601.22', 'documents' => 9, 'sales_net' => '8794.00',
            'vat_input' => '440.22', 'p39_source' => 'opening', 'sales_rows' => 6, 'excess_policy' => 'carry_forward', 'purchase_rows' => 5];
    }

    /** @return array<string,string> */
    private static function v7mDeclaration(): array
    {
        return ['P_11' => '6150', 'P_12' => '4250', 'P_19' => '2000', 'P_20' => '460', 'P_27' => '220', 'P_28' => '44', 'P_29' => '424', 'P_30' => '98', 'P_37' => '8794', 'P_38' => '602', 'P_39' => '150',
            'P_42' => '1914', 'P_43' => '440', 'P_48' => '590', 'P_51' => '12'];
    }

    /** @return list<array<string,mixed>> */
    private static function diItems(): array
    {
        return [['lp' => 5, 'reason' => 'wew', 'document' => 'WEW/2026/11/1', 'register' => 'sales', 'counterparty' => 'Fictional Payments Inc.'],
            ['lp' => 5, 'reason' => 'offline_without_number', 'document' => 'FZ/2026/11/9', 'register' => 'purchase', 'counterparty' => 'Fikcyjny Dostawca sp. z o.o. (fictional)']];
    }

    /** @return array<string,mixed> */
    private static function position(string $country, string $vatNumber, string $net, int $pUc, int $invoices): array
    {
        return ['country' => $country, 'vat_number' => $vatNumber, 'net_pln' => $net, 'p_uc' => $pUc, 'invoices' => $invoices];
    }

    /** @return array<mixed> */
    private function json(mixed $value): array
    {
        $decoded = json_decode(is_string($value) ? $value : '', true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return self::sorted($decoded);
    }

    /**
     * Klucze map posortowane rekurencyjnie (jsonb nie zachowuje kolejności); listy bez zmian.
     *
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function sorted(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map(static fn(mixed $item): mixed => is_array($item) ? self::sorted($item) : $item, $value);
    }
}
