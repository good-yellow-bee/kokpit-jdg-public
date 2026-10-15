<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\VatUeK12Gate;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\VatUeTestTools;
use App\Xml\JpkBuildRefused;
use App\Xml\VatUeDocument;

/**
 * Kryterium `vat-ue-k12-gate` (F5.5, D-66): suma niezaokrąglonych `net_pln` pozycji VAT-UE równa K_12 najnowszego
 * JPK_V7M okresu do grosza, różnica zaokrąglenia P_Uc najwyżej 0,50 zł × liczba kontrahentów. Różnica 0,01 zł
 * blokuje prośbę o zatwierdzenie z kwotą różnicy; brak JPK_V7M z K_12 też blokuje. Dane fikcyjne.
 */
final class VatUeK12GateTest extends VatUeTestCase
{
    public function testMatchingK12PassesAndRequestCarriesTotals(): void
    {
        $send = $this->vatUe('accepted');
        $approvalId = $send->request->vatUe(VatUeTestTools::PERIOD);
        $filing = $send->filing('VAT_UE', VatUeTestTools::PERIOD);
        $totals = json_decode(is_string($filing['totals']) ? $filing['totals'] : '{}', true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($totals);
        self::assertSame(['7217.93', '7217.93', 7218, '0.00', '0.07', '1.00', 2, 1], [$totals['k12'], $totals['net_pln_sum'], $totals['p_uc_sum'], $totals['difference'], $totals['rounding_difference'], $totals['tolerance'], $totals['buyers'], $totals['excluded_unidentified']]);
        self::assertSame('pending', $send->approval($approvalId)['status']);
        self::assertSame(0, $send->count("SELECT count(*) FROM connector_call WHERE connector='edeklaracje'"));
        $lines = ['k12_match: pass (net_pln_sum 7217.93 = K_12 7217.93, P_Uc 7218, różnica zaokrąglenia 0.07 <= 1.00)'];

        // Różnica jednego grosza: prośba nie powstaje, komunikat podaje kwotę różnicy.
        VatUeTestTools::v7mFiling($this->db(), $send->clock, $this->builder($send->clock), 'accepted', '7217.94');
        try {
            $send->request->vatUe(VatUeTestTools::PERIOD);
            self::fail('Różnica 0,01 zł musi blokować prośbę.');
        } catch (JpkBuildRefused $refused) {
            self::assertStringContainsString('o -0.01 zł', $refused->getMessage());
            $lines[] = 'k12_off_by_grosz: refused (' . $refused->getMessage() . ')';
        }
        self::assertSame(1, $send->count("SELECT count(*) FROM approval WHERE subject_type='filing' AND subject_id=?", [$filing['id']]));
        EvidenceArtefacts::write('cmd/vat-ue-k12-gate.txt', implode("\n", $lines) . "\n");
    }

    public function testMissingV7mRefuses(): void
    {
        $send = $this->vatUe(null);
        $this->expectException(JpkBuildRefused::class);
        $this->expectExceptionMessage('Brak JPK_V7M za 2026-11 z K_12');
        $send->request->vatUe(VatUeTestTools::PERIOD);
    }

    public function testRoundingDifferenceAboveToleranceRefuses(): void
    {
        $this->vatUe('ready');
        // Dokument z jedną pozycją i P_Uc odległym o więcej niż 0,50 zł (tylko w pamięci testu; generator tego nie robi).
        $document = new VatUeDocument('VAT_UE', VatUeTestTools::PERIOD, '<x/>', [['country' => 'DE', 'vat_number' => '999999999', 'net_pln' => '7217.93', 'p_uc' => 7219, 'invoices' => 1]], [], '7217.93', 7219, 1, 0, []);
        $this->expectException(JpkBuildRefused::class);
        $this->expectExceptionMessage('przekracza tolerancję 0.50 zł');
        (new VatUeK12Gate($this->db()))->check('test', $document);
    }
}
