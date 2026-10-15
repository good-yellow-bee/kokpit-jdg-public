<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ledger\ManualFiling;
use App\Ledger\MonthChecklist;
use App\Setting\SettingKeys;
use App\Tests\Support\ConnectorTestTools;
use App\Tests\Support\F1TestTools;
use App\Tests\Support\JpkTestTools;
use App\Tests\Support\JpkV7mTestTools;
use App\Xml\JpkBuildRefused;
use Symfony\Component\Clock\MockClock;

/**
 * Kryterium `jpk-v7m-declaration` (F5.4, D-65): deklaracja VAT-7 (23) w pełnych złotych. Miesiąc zerowy daje „0” w P_38
 * i P_51; nadwyżka przechodzi w całości do P_53 i P_62 (Q-31); pierwszy miesiąc bierze P_39 z
 * `opening.vat_carry_forward_pln`, a miesiąc po złożeniu `manual` z P_62 tego złożenia. Dane fikcyjne.
 */
final class JpkV7mDeclarationTest extends JpkV7mTestCase
{
    public function testZeroMonthHasZeroInP38AndP51(): void
    {
        $send = $this->v7m(seed: false);
        $settings = JpkTestTools::settings($this->db(), $send->clock);
        $settings->set(SettingKeys::COMPANY_IDENTITY, JpkTestTools::dataset()['company_identity'], '2026-01-01', null, 'fictional');
        $settings->set(SettingKeys::OPENING_VAT_CARRY_FORWARD, '0.00', '2026-10-01', null, 'fictional');
        $document = $this->builder($send->clock)->build('2026-10-01');
        self::assertSame([], $document->xsdErrors);
        self::assertSame(['P_38' => '0', 'P_48' => '0', 'P_51' => '0'], JpkV7mTestTools::declaration($document->xml));
        self::assertSame(['0', '0', 0, 0], [$document->totals['p53'], $document->totals['p62'], $document->totals['sales_rows'], $document->totals['purchase_rows']]);
        self::assertSame('0', JpkV7mTestTools::value($document->xml, '/v:JPK/v:Ewidencja/v:SprzedazCtrl/v:LiczbaWierszySprzedazy'));
    }

    public function testExcessGoesWholeToP62AndFirstMonthUsesOpening(): void
    {
        $send = $this->v7m(opening: false);
        $settings = JpkTestTools::settings($this->db(), $send->clock);
        // Bez źródła P_39 (brak złożenia za 10.2026 i bilansu z datą przejścia 11.2026) budowa jest zablokowana.
        try {
            $this->builder($send->clock)->build(JpkV7mTestTools::PERIOD);
            self::fail('Brak blokady bez P_39.');
        } catch (JpkBuildRefused $refused) {
            self::assertStringContainsString('opening.vat_carry_forward_pln', $refused->getMessage());
        }
        $settings->set(SettingKeys::OPENING_VAT_CARRY_FORWARD, '2000.00', JpkV7mTestTools::PERIOD, null, 'fictional');
        $document = $this->builder($send->clock)->build(JpkV7mTestTools::PERIOD);
        self::assertSame([], $document->xsdErrors);
        $declaration = JpkV7mTestTools::declaration($document->xml);
        // P_38 602 (z P_20 460, P_28 44, P_30 98 w pełnych złotych), P_48 = 2000 + 440 = 2440.
        self::assertSame(['602', '2000', '2440', '0', '1838', '1838'], [$declaration['P_38'], $declaration['P_39'], $declaration['P_48'], $declaration['P_51'], $declaration['P_53'], $declaration['P_62']]);
        self::assertSame(['P_48', 'P_51', 'P_53', 'P_62'], array_slice(array_keys($declaration), -4));
        self::assertSame(['opening', 'carry_forward', '1838'], [$document->totals['p39_source'], $document->totals['excess_policy'], $document->totals['p62']]);

        // Polityka inna niż przeniesienie (pola zwrotu P_54–P_61) nie jest dopuszczona (Q-31).
        $this->expectException(\InvalidArgumentException::class);
        $settings->set(SettingKeys::VAT_EXCESS_POLICY, 'refund_25_days', '2026-11-01', null, 'fictional');
    }

    public function testMonthAfterManualFilingTakesP39FromItsP62(): void
    {
        $send = $this->v7m(seed: false);
        $settings = JpkTestTools::settings($this->db(), $send->clock);
        $settings->set(SettingKeys::COMPANY_IDENTITY, JpkTestTools::dataset()['company_identity'], '2026-01-01', null, 'fictional');
        // Bilans otwarcia z datą przejścia 11.2026 nie jest źródłem P_39 grudnia: ma je złożenie `manual` za listopad.
        $settings->set(SettingKeys::OPENING_VAT_CARRY_FORWARD, '150.00', JpkV7mTestTools::PERIOD, null, 'fictional');
        $clock = new MockClock('2026-12-20T09:00:00Z');
        $this->manual($clock)->record($send->userId, 'fictional-step-up-credential', 'JPK_V7M', '2026-11', "%PDF-1.4\n% fikcyjne UPO (fictional)\n", 'application/pdf', 'FIKCYJNY-REF-V7M-11', null, '1838');
        $document = $this->builder(new MockClock('2027-01-10T09:00:00Z'))->build('2026-12-01');
        self::assertSame([], $document->xsdErrors);
        self::assertSame(['P_38' => '0', 'P_39' => '1838', 'P_48' => '1838', 'P_51' => '0', 'P_53' => '1838', 'P_62' => '1838'], JpkV7mTestTools::declaration($document->xml));
        self::assertSame('previous_filing', $document->p39Source);

        // Korekta `manual` za listopad zastępuje P_62 źródłowe (`superseded` wypada z wyboru).
        $this->db()->executeStatement("UPDATE period SET status='reopened', reopened_at=now() WHERE period_start='2026-11-01'");
        $this->manual($clock)->record($send->userId, 'fictional-step-up-credential', 'JPK_V7M', '2026-11', "%PDF-1.4\n% fikcyjne UPO korekty (fictional)\n", 'application/pdf', 'FIKCYJNY-REF-V7M-11K', null, '1700');
        self::assertSame('1700', $this->builder(new MockClock('2027-01-10T09:00:00Z'))->build('2026-12-01')->declaration['P_39']);
    }

    private function manual(MockClock $clock): ManualFiling
    {
        return new ManualFiling($this->db(), $clock, F1TestTools::audit($this->db(), $clock), F1TestTools::files($this->db(), $clock), F1TestTools::periods($this->db(), $clock), new MonthChecklist($this->db(), $clock, ConnectorTestTools::configuration(false)), ConnectorTestTools::configuration(false));
    }
}
