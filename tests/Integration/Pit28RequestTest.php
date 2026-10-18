<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\JpkSendTestTools;
use App\Xml\JpkBuildRefused;
use App\Xml\Pit28Builder;
use Brick\Math\BigDecimal;
use Symfony\Component\Uid\UuidV7;

/**
 * PIT-28 przez e-Deklaracje (D-96): złożenie `PIT_28` idzie konektorem `edeklaracje`, bez wariantu formularza z XSD
 * w `resources/xsd` prośba odmawia przed zatwierdzeniem i przed `xml_sent`, a przyjęcie zamyka obowiązek `pit_28` roku.
 * Kwoty zeznania pochodzą z ewidencji, `zus_payment` i wpłat zaliczek. Dane fikcyjne.
 */
final class Pit28RequestTest extends JpkSendTestCase
{
    public function testMissingVariantRefusesBeforeRequest(): void
    {
        $send = $this->send();
        try {
            $send->request->pit28(JpkSendTestTools::YEAR);
            self::fail('Prośba o PIT-28 przeszła bez wariantu z XSD.');
        } catch (JpkBuildRefused $refused) {
            self::assertSame(Pit28Builder::variantMissing(JpkSendTestTools::YEAR), $refused->getMessage());
        }
        self::assertSame(0, $send->count('SELECT count(*) FROM approval'));
        self::assertSame(0, $send->count("SELECT count(*) FROM stored_file WHERE kind='xml_sent'"));
        $filing = $send->filing('PIT_28');
        self::assertSame(['draft', 'PIT-28', '2026-12-31'], [$filing['status'], $filing['schema_version'], $filing['period_end']]);
        self::assertIsString($filing['id']);
        self::assertSame('edeklaracje', $send->subjects->require('filing')->connector($filing['id']));
    }

    public function testInputsSumLedgerDeductionsAndPaidAdvances(): void
    {
        $send = $this->send();
        $now = $send->clock->now()->format('Y-m-d\TH:i:s.uP');
        foreach ([['2026-01-01', '120.40'], ['2026-02-01', '99.60'], ['2025-12-01', '500.00']] as [$period, $paid]) {
            $this->obligation('ryczalt_advance', 'ryczalt_advance.monthly', $period, $now, $paid);
        }
        $inputs = $send->pit28->inputs(JpkSendTestTools::YEAR);
        $ledger = $this->db()->fetchOne("SELECT sum(amount_pln) FROM revenue_entry WHERE environment='test' AND revenue_date BETWEEN '2026-01-01' AND '2026-12-31'");
        self::assertIsString($ledger);
        self::assertSame((string) BigDecimal::of($ledger)->toScale(2), $inputs['revenue_total']);
        self::assertSame('220.00', $inputs['advances_paid']);
        self::assertNull($send->pit28->variant(JpkSendTestTools::YEAR));
    }

    public function testAcceptedPit28ClosesYearObligation(): void
    {
        $send = $this->send();
        $now = $send->clock->now()->format('Y-m-d\TH:i:s.uP');
        $obligation = $this->obligation('pit_28', 'pit_28.yearly', '2026-01-01', $now);
        $id = (string) UuidV7::generate();
        $this->db()->insert('filing', ['id' => $id,'environment' => 'test','type' => 'PIT_28','period_start' => '2026-01-01','period_end' => '2026-12-31','purpose' => 1,'seq' => 0,'status' => 'submitted',
            'schema_version' => 'PIT-28','totals' => '{}','created_at' => $now,'updated_at' => $now]);

        $send->subjects->require('filing')->onStatus($id, 'accepted', null);

        self::assertSame('accepted', $send->filing('PIT_28')['status']);
        self::assertSame(['done', $id], array_values((array) $this->db()->fetchAssociative('SELECT status, filing_id FROM obligation WHERE id=?', [$obligation])));
    }

    private function obligation(string $type, string $rule, string $period, string $now, ?string $paid = null): string
    {
        $id = (string) UuidV7::generate();
        $this->db()->insert('obligation', ['id' => $id,'environment' => 'test','type' => $type,'rule_key' => $rule,'period_start' => $period,'nominal_due_date' => '2027-04-30','due_date' => '2027-04-30',
            'status' => $paid === null ? 'open' : 'done','done_at' => $paid === null ? null : $now,'amount_paid' => $paid,'paid_on' => $paid === null ? null : '2026-03-20','dedupe_key' => 'fictional-pit28:' . $id,'created_at' => $now,'updated_at' => $now]);
        return $id;
    }
}
