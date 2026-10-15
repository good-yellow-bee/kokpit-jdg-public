<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\JpkSendTestTools;
use App\Tests\Support\JpkTestTools;
use App\Xml\JpkBuildRefused;
use App\Xml\JpkStBuilder;
use App\Xml\XsdCatalog;
use App\Xml\XsdValidator;
use Symfony\Component\Yaml\Yaml;

/**
 * Kryterium `jpk-st-request` (F5.2): JPK_ST(1) przez bramkę tą samą ścieżką co JPK_EWP(4). Przy `jpk_st.enabled` = false
 * na 31.12.2026 brak prośby i komunikat Q-25; przy true XML zgodny z XSD, `approval` `pending`, po zatwierdzeniu wysyłka
 * na `FakeJpkGateway` i `accepted` z UPO (D-64). Dane fikcyjne, bez sieci.
 */
final class JpkStRequestTest extends JpkSendTestCase
{
    public function testFlagOffRefusesThenFlagOnGoesThroughGateToAcceptedWithUpo(): void
    {
        $send = $this->send();
        JpkTestTools::seedFixedAssets($this->db(), $send->clock);
        $settings = JpkTestTools::settings($this->db(), $send->clock);

        // Flaga false (Q-25): komunikat, zero złożeń JPK_ST, zatwierdzeń, `xml_sent` i wywołań `jpk`.
        $this->assertRefusedQ25($send);
        // Flaga włączona dopiero po końcu roku nie obejmuje 2026 (jak obowiązek `jpk_st`, D-39).
        $settings->set('jpk_st.enabled', true, '2027-01-10', null, 'fictional');
        $this->assertRefusedQ25($send);

        $settings->set('jpk_st.enabled', true, '2026-12-01', null, 'fictional');
        $approvalId = $send->request->jpkSt(JpkSendTestTools::YEAR);
        $row = $send->approval($approvalId);
        self::assertSame(['pending', 'filing', 'jpk'], [$row['status'], $row['subject_type'], $row['connector']]);
        $filing = $send->filing('JPK_ST');
        self::assertSame(['ready', $filing['id'], 'JPK_ST(1)', '2026-12-31'], [$filing['status'], $row['subject_id'], $filing['schema_version'], $filing['period_end']]);
        $files = $this->db()->fetchAllAssociative("SELECT sha256 FROM stored_file WHERE kind='xml_sent' AND owner_table='filing' AND owner_id=?", [$filing['id']]);
        self::assertCount(1, $files);
        $xml = $send->payload($approvalId);
        EvidenceArtefacts::write('jpk/jpk-st-request.xml', $xml);
        self::assertSame([$row['payload_sha256'], $row['payload_sha256']], [hash('sha256', $xml), $files[0]['sha256']]);
        self::assertSame([], (new XsdValidator(XsdCatalog::default()))->validate($xml, JpkStBuilder::SCHEMA));
        self::assertSame(['1', '2', '3', '4'], array_column(JpkTestTools::stRows($xml), 'F_1'));
        $summary = json_decode(is_string($row['summary']) ? $row['summary'] : '', true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($summary);
        self::assertSame(['JPK_ST', '2026', 1, 4, '20500.00', 6], [$summary['type'], $summary['period'], $summary['purpose'], $summary['rows'], $summary['sum_f10'], $summary['fixed_asset_count']]);
        // JPK_EWP tego roku nie powstaje przy prośbie JPK_ST.
        self::assertSame(0, $send->count("SELECT count(*) FROM filing WHERE type='JPK_EWP'"));

        // Zatwierdzenie ze step-upem i wysyłka przez `OfficialWritesHandler` na `FakeJpkGateway`.
        $send->gate->approve($approvalId, $send->userId);
        $send->loadUpload();
        $send->fake->load('api-status-120', 'api-status-200');
        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        self::assertSame(['submitted', JpkSendTestTools::REFERENCE, 'jpk'], [$send->filing('JPK_ST')['status'], $send->filing('JPK_ST')['reference_number'], $send->filing('JPK_ST')['submitted_via']]);
        self::assertSame(0, $send->reconciler->poll());
        self::assertSame(1, $send->reconciler->poll());
        self::assertSame(['accepted', '200'], [$send->approval($approvalId)['status'], $send->approval($approvalId)['result_code']]);
        $accepted = $send->filing('JPK_ST');
        self::assertSame(['accepted', 'jpk', 200], [$accepted['status'], $accepted['submitted_via'], $accepted['status_code']]);
        $refs = $send->refs($approvalId);
        $upo = $this->db()->fetchAssociative("SELECT id, owner_table, owner_id FROM stored_file WHERE kind='upo'");
        self::assertIsArray($upo);
        self::assertSame([$refs['upo_file_id'], 'filing', $accepted['id']], [$upo['id'], $upo['owner_table'], $upo['owner_id']]);
        self::assertStringContainsString(JpkSendTestTools::REFERENCE, $send->files->read($refs['upo_file_id']));

        // Wysłany plik to dokładnie `xml_sent` JPK_ST; `FormCode` z nagłówka JPK_ST(1).
        $metadata = $send->fake->metadata();
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($metadata, LIBXML_NONET));
        $form = $dom->getElementsByTagNameNS('*', 'FormCode')->item(0);
        self::assertInstanceOf(\DOMElement::class, $form);
        self::assertSame(['JPK_ST', 'JPK_ST (1)', '1-2'], [$form->textContent, $form->getAttribute('systemCode'), $form->getAttribute('schemaVersion')]);
        self::assertStringStartsWith('JPK_ST_20260101_', self::text($dom, 'FileName'));
        [, $sent] = JpkSendTestTools::unzip(JpkSendTestTools::decrypt($send->fake->puts(), self::text($dom, 'EncryptionKey'), self::text($dom, 'IV')));
        self::assertSame($xml, $sent);
        self::assertSame(['approval.requested', 'approval.approved', 'dispatch.started', 'dispatch.submitted', 'dispatch.accepted'], $send->auditActions($approvalId));
        self::assertSame(0, $send->fake->recordings->remaining());

        // Wiersze F2 dopisane w tej sesji według D-37.
        $f2 = Yaml::parseFile(dirname(__DIR__) . '/Acceptance/f2.yaml');
        self::assertIsArray($f2);
        $keys = array_column(is_array($f2['criteria'] ?? null) ? $f2['criteria'] : [], 'key');
        self::assertContains('jpk-st-build', $keys);
        self::assertContains('jpk-st-dry-run', $keys);
    }

    private static function text(\DOMDocument $dom, string $name): string
    {
        $node = $dom->getElementsByTagNameNS('*', $name)->item(0);
        self::assertInstanceOf(\DOMElement::class, $node);
        return $node->textContent;
    }

    private function assertRefusedQ25(JpkSendTestTools $send): void
    {
        try {
            $send->request->jpkSt(JpkSendTestTools::YEAR);
            self::fail('Prośba JPK_ST przeszła przy wyłączonej fladze.');
        } catch (JpkBuildRefused $refused) {
            self::assertStringContainsString('jpk_st.enabled = false', $refused->getMessage());
            self::assertStringContainsString('Q-25', $refused->getMessage());
        }
        self::assertSame(0, $send->count("SELECT count(*) FROM filing WHERE type='JPK_ST'"));
        self::assertSame(0, $send->count('SELECT count(*) FROM approval'));
        self::assertSame(0, $send->count("SELECT count(*) FROM stored_file WHERE kind='xml_sent'"));
        self::assertSame(0, $send->calls());
    }
}
