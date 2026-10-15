<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\JpkSendTestTools;
use App\Tests\Support\JpkV7mTestTools;
use App\Xml\JpkV7mBuilder;
use App\Xml\XsdCatalog;
use App\Xml\XsdValidator;
use Symfony\Component\Uid\UuidV7;

/**
 * Kryterium `jpk-v7m-send` (F5.4, D-65): JPK_V7M za 11.2026 przez bramkę na `FakeJpkGateway` ścieżką F5.1 (`FilingSubject`,
 * `JpkWriter`, `OfficialWritesHandler`, `SubmissionReconciler`). Wynik: `accepted` z UPO, okres `closed` z
 * `closed_by_filing_id`, obowiązek `vat_jpk_v7m` za M `done` z `filing_id`. Dane fikcyjne, bez sieci.
 */
final class JpkV7mSendTest extends JpkV7mTestCase
{
    public function testAcceptedFilingClosesPeriodAndCompletesObligation(): void
    {
        $send = $this->v7m(opening: false);
        JpkV7mTestTools::confirmOpening($send, $this->db());
        $obligation = $this->obligation($send, 'vat_jpk_v7m', '2026-12-28');
        $vatUe = $this->obligation($send, 'vat_ue', '2026-12-28');

        $approvalId = $send->request->jpkV7m(JpkV7mTestTools::PERIOD);
        $xml = $send->payload($approvalId);
        self::assertSame([], (new XsdValidator(XsdCatalog::default()))->validate($xml, JpkV7mBuilder::SCHEMA));
        // Prośba nie zamyka okresu; wysyłka tylko przez bramkę i handler `official_writes`.
        self::assertSame(0, $send->calls());
        self::assertNotSame('closed', $send->periods->status(JpkV7mTestTools::PERIOD));

        $send->gate->approve($approvalId, $send->userId);
        $send->loadUpload();
        $send->fake->load('api-status-120', 'api-status-200');
        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        self::assertSame(0, $send->reconciler->poll());
        self::assertSame(1, $send->reconciler->poll());
        $filing = $send->filing('JPK_V7M', JpkV7mTestTools::PERIOD);
        self::assertSame(['accepted', 'jpk', 200, JpkSendTestTools::REFERENCE], [$filing['status'], $filing['submitted_via'], $filing['status_code'], $filing['reference_number']]);
        $refs = $send->refs($approvalId);
        self::assertStringContainsString(JpkSendTestTools::REFERENCE, $send->files->read($refs['upo_file_id']));

        // Wysłane bajty to `xml_sent`; `FormCode` z nagłówka JPK_V7M(3).
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($send->fake->metadata(), LIBXML_NONET));
        $form = $dom->getElementsByTagNameNS('*', 'FormCode')->item(0);
        self::assertInstanceOf(\DOMElement::class, $form);
        self::assertSame(['JPK_VAT', 'JPK_V7M (3)', '1-0E'], [$form->textContent, $form->getAttribute('systemCode'), $form->getAttribute('schemaVersion')]);
        $key = $dom->getElementsByTagNameNS('*', 'EncryptionKey')->item(0);
        $iv = $dom->getElementsByTagNameNS('*', 'IV')->item(0);
        self::assertNotNull($key);
        self::assertNotNull($iv);
        [, $sent] = JpkSendTestTools::unzip(JpkSendTestTools::decrypt($send->fake->puts(), $key->textContent, $iv->textContent));
        self::assertSame($xml, $sent);

        $period = $this->db()->fetchAssociative("SELECT period_start, status, closed_by_filing_id, closed_at, checklist IS NOT NULL AS checklist_saved FROM period WHERE environment='test' AND period_start=?", [JpkV7mTestTools::PERIOD]);
        self::assertIsArray($period);
        EvidenceArtefacts::write('db/jpk-v7m-send-period.csv', EvidenceArtefacts::csv([$period]));
        self::assertSame(['closed', $filing['id'], true], [$period['status'], $period['closed_by_filing_id'], $period['checklist_saved']]);
        $done = $this->db()->fetchAssociative('SELECT status, filing_id, done_by FROM obligation WHERE id=?', [$obligation]);
        self::assertSame(['status' => 'done', 'filing_id' => $filing['id'], 'done_by' => $send->userId], $done);
        // Miesiąc ze sprzedażą `eu_b2b` zostawia `vat_ue` otwarte (VAT-UE w F5.5).
        self::assertSame('open', $this->db()->fetchOne('SELECT status FROM obligation WHERE id=?', [$vatUe]));
        self::assertTrue($send->periods->closed(JpkV7mTestTools::PERIOD));
        self::assertSame(['approval.requested', 'approval.approved', 'dispatch.started', 'dispatch.submitted', 'dispatch.accepted'], $send->auditActions($approvalId));
        self::assertSame(1, $send->count("SELECT count(*) FROM audit_log WHERE action='period.closed' AND details->>'filing_id'=?", [$filing['id']]));
        self::assertSame(0, $send->fake->recordings->remaining());

        // Drugie pierwsze złożenie za zamknięty okres jest odrzucone.
        $this->expectException(\App\Xml\JpkBuildRefused::class);
        $send->request->jpkV7m(JpkV7mTestTools::PERIOD);
    }

    private function obligation(JpkSendTestTools $send, string $type, string $due): string
    {
        $id = (string) UuidV7::generate($send->clock->now());
        $stamp = $send->clock->now()->format('Y-m-d\TH:i:s.uP');
        $this->db()->insert('obligation', ['id' => $id, 'environment' => 'test', 'type' => $type, 'rule_key' => $type . '.monthly', 'period_start' => JpkV7mTestTools::PERIOD, 'nominal_due_date' => '2026-12-25',
            'due_date' => $due, 'status' => 'open', 'dedupe_key' => $type . ':' . JpkV7mTestTools::PERIOD, 'created_at' => $stamp, 'updated_at' => $stamp]);
        return $id;
    }
}
