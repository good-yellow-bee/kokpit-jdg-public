<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\MonthChecklist;
use App\Ledger\VatUeK12Gate;
use App\Security\SecretStore;
use App\Setting\SettingKeys;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\JpkSendTestTools;
use App\Tests\Support\JpkTestTools;
use App\Tests\Support\JpkV7mTestTools;
use App\Xml\JpkBuildRefused;
use App\Xml\VatUeDocument;
use Symfony\Component\Uid\UuidV7;

/**
 * Wynik bramek przed budową: `MonthChecklist` (pusty miesiąc), `V7mGates` (`eu_b2b_unidentified` z przeglądem
 * `ksef.fa3_review` i dowodem ręcznym, `opening_balance`) oraz `VatUeK12Gate` (pełny wynik i komunikat różnicy). Dane fikcyjne.
 */
final class FilingGatesResultTest extends DatabaseTestCase
{
    private const PERIOD = JpkV7mTestTools::PERIOD;

    public function testEmptyMonthChecklistIsOkWithTimestamp(): void
    {
        $send = $this->send();
        $checklist = (new MonthChecklist($this->db(), $send->clock, ConnectorTestTools::configuration()))->evaluate('2026-11-17');
        self::assertSame(['checked_at' => '2026-12-10T09:00:00.000000+00:00', 'period_start' => self::PERIOD, 'ok' => true,
            'gates' => array_fill_keys(MonthChecklist::GATES, ['ok' => true, 'count' => 0])], $checklist);
    }

    public function testUnidentifiedEuSaleNeedsApprovedScenarioAndManualEvidence(): void
    {
        $send = $this->send();
        $counterparty = $this->unidentifiedSale($send);
        // Dowód ręczny bez zatwierdzonego scenariusza nie wystarcza.
        $this->db()->insert('verification', ['id' => (string) UuidV7::generate($send->clock->now()), 'environment' => 'test', 'counterparty_id' => $counterparty, 'source' => null, 'subject' => 'Fikcyjny dowód (fictional)',
            'checked_for_date' => '2026-11-20', 'checked_at' => '2026-11-20T10:00:00Z', 'result' => 'manual_evidence']);
        self::assertSame(['ok' => false, 'count' => 1], $send->v7mGates->evaluate(self::PERIOD)['gates']['eu_b2b_unidentified']);
        JpkTestTools::settings($this->db(), $send->clock)->set(SettingKeys::KSEF_FA3_REVIEW, ['reviewed_on' => '2026-11-10', 'scenarios' => ['eu_b2b', 'eu_b2b_unidentified'], 'kor' => false, 'golden_sha256' => []], self::PERIOD, null, 'fictional');
        self::assertSame(['ok' => true, 'count' => 0], $send->v7mGates->evaluate(self::PERIOD)['gates']['eu_b2b_unidentified']);
    }

    public function testApprovedScenarioWithoutManualEvidenceStillBlocks(): void
    {
        $send = $this->send();
        $this->unidentifiedSale($send);
        JpkTestTools::settings($this->db(), $send->clock)->set(SettingKeys::KSEF_FA3_REVIEW, ['reviewed_on' => '2026-11-10', 'scenarios' => ['eu_b2b_unidentified'], 'kor' => false, 'golden_sha256' => []], self::PERIOD, null, 'fictional');
        self::assertSame(['ok' => false, 'count' => 1], $send->v7mGates->evaluate(self::PERIOD)['gates']['eu_b2b_unidentified']);
    }

    public function testOpeningBalanceGateCountsMissingSource(): void
    {
        $send = $this->send();
        $result = $send->v7mGates->evaluate(self::PERIOD);
        self::assertSame([false, ['ok' => false, 'count' => 1]], [$result['ok'], $result['gates']['opening_balance']]);
        $this->v7mFiling($send, '2026-10-01', 'accepted', '{}');
        $result = $send->v7mGates->evaluate(self::PERIOD);
        self::assertSame([true, ['ok' => true, 'count' => 0]], [$result['ok'], $result['gates']['opening_balance']]);
    }

    public function testK12GateReturnsTextAmountsAndRefusesDifferenceWithMonth(): void
    {
        $send = $this->send();
        $v7m = $this->v7mFiling($send, self::PERIOD, 'ready', '{"k12": "7217.93"}');
        $positions = [['country' => 'DE', 'vat_number' => '999999999', 'net_pln' => '3824.53', 'p_uc' => 3825, 'invoices' => 2], ['country' => 'FR', 'vat_number' => '99999999999', 'net_pln' => '3393.40', 'p_uc' => 3393, 'invoices' => 1]];
        $document = new VatUeDocument('VAT_UE', self::PERIOD, '<x/>', $positions, [], '7217.93', 7218, 3, 0, []);
        self::assertSame(
            ['k12' => '7217.93', 'net_pln_sum' => '7217.93', 'p_uc_sum' => 7218, 'difference' => '0.00', 'rounding_difference' => '0.07', 'tolerance' => '1.00', 'v7m_filing_id' => $v7m],
            (new VatUeK12Gate($this->db()))->check('test', $document),
        );
        $this->db()->update('filing', ['totals' => '{"k12": "7217.94"}'], ['id' => $v7m]);
        try {
            (new VatUeK12Gate($this->db()))->check('test', $document);
            self::fail('Różnica K_12 przeszła.');
        } catch (JpkBuildRefused $refused) {
            self::assertSame('Suma net_pln VAT-UE 7217.93 różna od K_12 7217.94 JPK_V7M za 2026-11 o -0.01 zł; prośba nie powstaje.', $refused->getMessage());
        }
    }

    private function send(): JpkSendTestTools
    {
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        return new JpkSendTestTools($this->db(), $secrets, JpkV7mTestTools::NOW);
    }

    /** Fikcyjna sprzedaż `eu_b2b_unidentified` z 11.2026; zwraca kontrahenta. */
    private function unidentifiedSale(JpkSendTestTools $send): string
    {
        $stamp = $send->clock->now()->format('Y-m-d\TH:i:s.uP');
        $counterparty = (string) UuidV7::generate($send->clock->now());
        $this->db()->insert('counterparty', ['id' => $counterparty, 'environment' => 'test', 'name' => 'Fictional Buyer GmbH', 'country' => 'DE', 'address' => '{}', 'bank_accounts' => '[]',
            'is_client' => 'true', 'is_supplier' => 'false', 'is_natural_person' => 'false', 'created_at' => $stamp, 'updated_at' => $stamp]);
        JpkV7mTestTools::invoice($this->db(), $send->clock, ['direction' => 'sale', 'status' => 'accepted', 'tax_scenario' => 'eu_b2b_unidentified', 'ksef_issue_mode' => 'online', 'invoice_number' => 'FV/2026/11/77',
            'counterparty_id' => $counterparty, 'currency' => 'PLN', 'issue_date' => '2026-11-18', 'tax_point_date' => '2026-11-18', 'vat_period_start' => self::PERIOD,
            'net_total' => '100.00', 'vat_total' => '0.00', 'gross_total' => '100.00', 'net_pln' => '100.00', 'vat_pln' => '0.00']);
        return $counterparty;
    }

    private function v7mFiling(JpkSendTestTools $send, string $periodStart, string $status, string $totals): string
    {
        $id = (string) UuidV7::generate($send->clock->now());
        $stamp = $send->clock->now()->format('Y-m-d\TH:i:s.uP');
        $this->db()->insert('filing', ['id' => $id, 'environment' => 'test', 'type' => 'JPK_V7M', 'period_start' => $periodStart, 'period_end' => (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d'),
            'purpose' => 1, 'seq' => 0, 'status' => $status, 'schema_version' => 'JPK_V7M(3)', 'totals' => $totals, 'created_at' => $stamp, 'updated_at' => $stamp]);
        return $id;
    }
}
