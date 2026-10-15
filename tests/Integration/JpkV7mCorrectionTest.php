<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\JpkSendTestTools;
use App\Tests\Support\JpkV7mTestTools;
use App\Xml\JpkBuildRefused;

/**
 * Kryterium `jpk-v7m-correction` (F5.4, D-65): przyjęty JPK_V7M zamyka 11.2026; korekta wymaga ponownego otwarcia ze
 * step-up i powstaje z `CelZlozenia` 2, P_ORDZU, kolejnym `seq` i `supersedes_id`. Po przyjęciu korekty poprzednie
 * złożenie jest `superseded`, a okres znów `closed`. Dane fikcyjne, wysyłka na `FakeJpkGateway`.
 */
final class JpkV7mCorrectionTest extends JpkV7mTestCase
{
    private const REASON = 'Fikcyjna korekta: późno otrzymana faktura kosztowa za 11.2026 (fictional).';

    public function testCorrectionAfterReopenSupersedesAndClosesAgain(): void
    {
        $send = $this->v7m(opening: false);
        JpkV7mTestTools::confirmOpening($send, $this->db());
        $first = $send->request->jpkV7m(JpkV7mTestTools::PERIOD);
        $this->accept($send, $first);
        $original = $send->filing('JPK_V7M', JpkV7mTestTools::PERIOD);
        self::assertSame('closed', $send->periods->status(JpkV7mTestTools::PERIOD));

        // Korekta bez ponownego otwarcia jest odrzucona.
        try {
            $send->request->jpkV7mCorrection(JpkV7mTestTools::PERIOD, self::REASON);
            self::fail('Korekta bez ponownego otwarcia.');
        } catch (JpkBuildRefused $refused) {
            self::assertStringContainsString('ponownym otwarciu', $refused->getMessage());
        }
        // Ponowne otwarcie ze step-up (passkey w audycie) i późny koszt przypisany do 11.2026.
        $send->periods->reopen(JpkV7mTestTools::PERIOD, 'Fikcyjny powód: późna faktura kosztowa', $send->userId, 'fictional-step-up-credential');
        $supplier = $this->db()->fetchOne("SELECT id FROM counterparty WHERE nip='7777777777'");
        JpkV7mTestTools::invoice($this->db(), $send->clock, ['direction' => 'cost', 'cost_status' => 'assigned', 'cost_review' => 'business', 'source' => 'paper', 'ksef_issue_mode' => 'outside_ksef',
            'invoice_number' => 'P/2026/11/6', 'counterparty_id' => $supplier, 'currency' => 'PLN', 'issue_date' => '2026-11-27', 'received_on' => '2026-11-28', 'tax_point_date' => '2026-11-27', 'vat_period_start' => JpkV7mTestTools::PERIOD,
            'net_total' => '100.00', 'vat_total' => '23.00', 'gross_total' => '123.00', 'net_pln' => '100.00', 'vat_pln' => '23.00', 'vat_exempt' => 'false', 'deductible' => 'true', 'is_fixed_asset' => 'false']);

        $second = $send->request->jpkV7mCorrection(JpkV7mTestTools::PERIOD, self::REASON);
        $xml = $send->payload($second);
        self::assertSame('2', JpkV7mTestTools::value($xml, '/v:JPK/v:Naglowek/v:CelZlozenia'));
        $declaration = JpkV7mTestTools::declaration($xml);
        self::assertSame([self::REASON, '2014', '463', '613', '0'], [$declaration['P_ORDZU'], $declaration['P_42'], $declaration['P_43'], $declaration['P_48'], $declaration['P_51']]);
        $summary = json_decode(is_string($send->approval($second)['summary']) ? $send->approval($second)['summary'] : '', true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($summary);
        self::assertIsArray($summary['previous']);
        self::assertSame([2, self::REASON, '12', '0'], [$summary['purpose'], $summary['p_ordzu'], $summary['previous']['p51'], $summary['previous']['p62']]);
        $this->accept($send, $second);

        $correction = $send->filing('JPK_V7M', JpkV7mTestTools::PERIOD, 1);
        $rows = $this->db()->fetchAllAssociative("SELECT id, type, period_start, purpose, seq, status, supersedes_id, submitted_via, totals->>'p_ordzu' AS p_ordzu, totals->>'p62' AS p62 FROM filing WHERE environment='test' AND type='JPK_V7M' ORDER BY seq");
        EvidenceArtefacts::write('db/jpk-v7m-correction-filing.csv', EvidenceArtefacts::csv($rows));
        self::assertSame([[0, 1, 'superseded', null], [1, 2, 'accepted', $original['id']]], array_map(static fn(array $row): array => [$row['seq'], $row['purpose'], $row['status'], $row['supersedes_id']], $rows));
        self::assertSame(self::REASON, $rows[1]['p_ordzu']);
        $period = $this->db()->fetchAssociative("SELECT status, closed_by_filing_id FROM period WHERE environment='test' AND period_start=?", [JpkV7mTestTools::PERIOD]);
        self::assertSame(['status' => 'closed', 'closed_by_filing_id' => $correction['id']], $period);
        self::assertSame([], $send->periods->corrections());
    }

    private function accept(JpkSendTestTools $send, string $approvalId): void
    {
        $send->gate->approve($approvalId, $send->userId);
        $send->loadUpload();
        $send->fake->load('api-status-200');
        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        self::assertSame(1, $send->reconciler->poll());
        self::assertSame('accepted', $send->approval($approvalId)['status']);
    }
}
