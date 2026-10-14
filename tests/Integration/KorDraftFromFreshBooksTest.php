<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\CorrectionTestTools;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\SalesTestTools;

/**
 * Kryterium `kor-draft-from-freshbooks` (F3.2 część b, Q-27): credit note do przyjętej faktury daje szkic KOR z różnicą;
 * edycja kwoty przyjętej faktury bez credit note daje szkic KOR bez numeru, bez `ready`; zmiana samego numeru daje tylko
 * alert; KOR do faktury bez numeru KSeF i bez `NrKSeFN` jest zablokowana. Credit notes, numery i kwoty są fikcyjne.
 */
final class KorDraftFromFreshBooksTest extends SalesTestCase
{
    public function testCreditNoteCreatesKorDraftWithDifference(): void
    {
        $sales = $this->tools();
        $sales->prepare();
        $tools = $this->corrections($sales);
        $original = $tools->accepted();
        self::assertSame(['kor_draft_created' => 1], $tools->pollCreditNotes([3001 => []]));
        $kor = $this->kor($original);
        self::assertSame(['KOR', 'CN-2026-0001', 'FB-2026-0201', CorrectionTestTools::KSEF_NUMBER, '3001', '-200.00'], [$kor['kind'], $kor['invoice_number'], $kor['corrected_invoice_number'], $kor['corrected_ksef_number'], $kor['freshbooks_credit_id'], $kor['net_total']]);
        self::assertSame([[1, true, '2.000000', '500.00000000', '1000.00'], [1, false, '1.000000', '800.00000000', '800.00']], $this->lines($kor['id']));
        // Szkic KOR spełnia warunki: `ready` i prośba `correction_invoice`; nic nie trafia do KSeF.
        self::assertSame('ready', $kor['status']);
        self::assertSame(1, $sales->count("SELECT count(*) FROM approval WHERE subject_type='correction_invoice' AND status='pending' AND subject_id=?", [$kor['id']]));
        self::assertSame(0, $sales->count("SELECT count(*) FROM connector_call WHERE connector='ksef'"));
        // Ponowny odczyt tej samej credit note nic nie zmienia.
        self::assertSame(['kor_unchanged' => 1], $tools->pollCreditNotes([3001 => []]));
        self::assertSame(1, $sales->count("SELECT count(*) FROM invoice WHERE kind='KOR'"));
        EvidenceArtefacts::write('db/kor-draft-from-freshbooks-invoice.csv', EvidenceArtefacts::csv([$this->kor($original)]));
    }

    public function testEditOfAcceptedInvoiceWaitsForCreditNoteNumber(): void
    {
        $sales = $this->tools();
        $sales->prepare();
        $tools = $this->corrections($sales);
        $original = $tools->accepted();
        $line = ['lineid' => 1,'name' => 'Development','description' => 'Fikcyjne prace programistyczne','qty' => '2','unit_cost' => ['amount' => '450.00','code' => 'PLN'],'amount' => ['amount' => '900.00','code' => 'PLN'],'taxAmount1' => '207.00','taxAmount2' => '0'];
        self::assertSame(['edited_after_acceptance' => 1], $sales->poll([SalesTestTools::PL => ['v3_status' => 'sent','lines' => [$line],'amount' => ['amount' => '1107.00','code' => 'PLN']]]));
        $kor = $this->kor($original);
        self::assertSame(['draft', null, '-100.00'], [$kor['status'], $kor['invoice_number'], $kor['net_total']]);
        self::assertSame([[1, true, '2.000000', '500.00000000', '1000.00'], [1, false, '2.000000', '450.00000000', '900.00']], $this->lines($kor['id']));
        self::assertContains('KOR bez numeru credit note (P_2); wystaw credit note we FreshBooks z linią „Faktura: <numer>” w notatce.', $sales->blockers($kor['id']));
        self::assertSame(0, $sales->count('SELECT count(*) FROM approval WHERE subject_id=?', [$kor['id']]));
        // Faktura przyjęta zostaje bez zmian.
        self::assertSame(['accepted', '1000.00'], [$sales->invoice($original)['status'], $this->db()->fetchOne('SELECT net_total FROM invoice WHERE id=?', [$original])]);
        // Credit note do tej faktury nadaje szkicowi numer; pozycje wynikają z uznania.
        self::assertSame(['kor_draft_updated' => 1], $tools->pollCreditNotes([3001 => ['amount' => ['amount' => '100.00','code' => 'PLN'],'lines' => [['lineid' => 1,'name' => 'Development','description' => 'Fikcyjny rabat','qty' => '1','unit_cost' => ['amount' => '100.00','code' => 'PLN'],'amount' => ['amount' => '100.00','code' => 'PLN'],'taxAmount1' => '23.00','taxAmount2' => '0']]]]));
        $numbered = $this->kor($original);
        self::assertSame([$kor['id'], 'CN-2026-0001', 'ready'], [$numbered['id'], $numbered['invoice_number'], $numbered['status']]);
        self::assertSame(1, $sales->count("SELECT count(*) FROM invoice WHERE kind='KOR'"));
    }

    public function testNumberOnlyChangeRaisesAlertWithoutKor(): void
    {
        $sales = $this->tools();
        $sales->prepare();
        $tools = $this->corrections($sales);
        $original = $tools->accepted();
        self::assertSame(['number_changed' => 1], $sales->poll([SalesTestTools::PL => ['v3_status' => 'sent','invoice_number' => 'FB-2026-0277']]));
        self::assertSame(0, $sales->count("SELECT count(*) FROM invoice WHERE kind='KOR'"));
        self::assertSame(['invoice_number' => 'FB-2026-0201','panel_task' => 'freshbooks_number_changed'], $this->db()->fetchAssociative('SELECT invoice_number, panel_task FROM invoice WHERE id=?', [$original]));
        self::assertSame(1, $sales->count("SELECT count(*) FROM notification WHERE category='freshbooks_number_changed'"));
        $sales->poll([SalesTestTools::PL => ['v3_status' => 'sent','invoice_number' => 'FB-2026-0277']]);
        self::assertSame(1, $sales->count("SELECT count(*) FROM notification WHERE category='freshbooks_number_changed'"));
    }

    public function testKorWithoutKsefNumberOrNrKsefNIsBlocked(): void
    {
        $sales = $this->tools();
        $sales->prepare();
        $tools = $this->corrections($sales);
        $sales->pending();
        // Credit note do faktury nieznanej kokpitowi (sprzed przejścia, bez numeru KSeF), bez oznaczenia NrKSeFN.
        self::assertSame(['kor_draft_created' => 1], $tools->pollCreditNotes([3002 => []]));
        $kor = $this->db()->fetchAssociative("SELECT id, status, invoice_number, corrects_invoice_id, corrected_invoice_number FROM invoice WHERE kind='KOR' AND freshbooks_credit_id='3002'");
        self::assertIsArray($kor);
        self::assertSame(['draft', 'CN-2026-0002', null, 'FB-2025-0999'], [$kor['status'], $kor['invoice_number'], $kor['corrects_invoice_id'], $kor['corrected_invoice_number']]);
        $id = is_string($kor['id']) ? $kor['id'] : '';
        self::assertContains('KOR bez faktury korygowanej z numerem KSeF i bez oznaczenia NrKSeFN.', $sales->blockers($id));
        self::assertSame(0, $sales->count('SELECT count(*) FROM approval WHERE subject_id=?', [$id]));

        // Credit note do faktury jeszcze nieprzyjętej w KSeF (szkic `ready`): KOR zablokowana.
        self::assertSame(['kor_draft_created' => 1], $tools->pollCreditNotes([3001 => []]));
        $draftKor = $this->db()->fetchOne("SELECT id FROM invoice WHERE kind='KOR' AND freshbooks_credit_id='3001'");
        self::assertIsString($draftKor);
        self::assertContains('Faktura korygowana nie jest przyjęta z numerem KSeF.', $sales->blockers($draftKor));
        self::assertSame(0, $sales->count("SELECT count(*) FROM approval WHERE subject_type='correction_invoice'"));
    }

    /** @return array<string,mixed> */
    private function kor(string $originalId): array
    {
        $row = $this->db()->fetchAssociative("SELECT id, kind, status, invoice_number, corrected_invoice_number, corrected_ksef_number, corrects_invoice_id, freshbooks_credit_id, net_total, ready_blockers FROM invoice WHERE kind='KOR' AND corrects_invoice_id=?", [$originalId]);
        self::assertIsArray($row, 'Brak szkicu KOR.');
        return $row;
    }

    /** @return list<list<mixed>> numer, stan przed, ilość, cena, netto */
    private function lines(mixed $korId): array
    {
        return array_map(static fn(array $row): array => array_values($row), $this->db()->fetchAllAssociative('SELECT line_no, state_before, quantity, unit_net_price, net_amount FROM invoice_line WHERE invoice_id=? ORDER BY line_no, state_before DESC', [$korId]));
    }
}
