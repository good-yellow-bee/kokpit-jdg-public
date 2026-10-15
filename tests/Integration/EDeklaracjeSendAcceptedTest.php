<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\Jpk\AuthDataDocument;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\FakeEDeklaracjeGateway;
use App\Tests\Support\VatUeTestTools;
use App\Xml\VatUeBuilder;
use App\Xml\XsdCatalog;
use App\Xml\XsdValidator;

/**
 * Kryterium `edeklaracje-send-accepted` (F5.5, D-66): VAT-UE za 11.2026 przez bramkę (`FilingSubject`,
 * `OfficialWritesHandler`, `EDeklaracjeWriter`) na `FakeEDeklaracjeGateway`: `sendUnsignDocument`, `requestUPO` 301,
 * potem 200 z UPO; złożenie `accepted` z `submitted_via` = `edeklaracje`, obowiązek `vat_ue` `done`. `xml_sent` nie ma
 * `DaneAutoryzujace` (hash zatwierdzenia wiąże deklarację), wysłany dokument to `xml_sent` z jednym elementem SIG-2008 na
 * końcu korzenia, a `connector_call.request_sha256` to hash faktycznego żądania. Data urodzenia i kwota N−2 nie trafiają
 * do `connector_call`, logów, `messenger_messages` ani `audit_log`. Dane fikcyjne, bez sieci.
 */
final class EDeklaracjeSendAcceptedTest extends VatUeTestCase
{
    public function testVatUeAcceptedThroughGateWithAuthDataOnlyInWorkerRequest(): void
    {
        $send = $this->vatUe('accepted');
        $approvalId = $send->request->vatUe(VatUeTestTools::PERIOD);
        $payload = $send->payload($approvalId);
        $approval = $send->approval($approvalId);
        self::assertSame(['edeklaracje', hash('sha256', $payload)], [$approval['connector'], $approval['payload_sha256']]);
        self::assertStringNotContainsString('DaneAutoryzujace', $payload);
        self::assertSame(0, $send->count("SELECT count(*) FROM connector_call WHERE connector='edeklaracje'"));

        $send->gate->approve($approvalId, $send->userId);
        $send->edeklaracje->load('soap-send', 'soap-upo-301', 'soap-upo-200');
        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        self::assertSame(FakeEDeklaracjeGateway::REF_ID, $send->refs($approvalId)['ref_id']);
        self::assertSame(0, $send->reconciler->poll());
        self::assertSame(1, $send->reconciler->poll());
        self::assertSame(0, $send->edeklaracje->recordings->remaining());
        self::assertSame(1, $send->edeklaracje->requests('sendUnsignDocument'));
        self::assertSame(2, $send->edeklaracje->requests('requestUPO'));
        // Ciała żądań zgodne z opublikowanym `GateService.xsd` bramki testowej (D-67).
        $gateSchema = new XsdValidator(XsdCatalog::default());
        foreach ($send->edeklaracje->soapBodies() as $soapBody) {
            self::assertSame([], $gateSchema->validate($soapBody, 'GateService.xsd'));
        }

        $filing = $send->filing('VAT_UE', VatUeTestTools::PERIOD);
        self::assertSame(['accepted', 'edeklaracje', 200, FakeEDeklaracjeGateway::REF_ID], [$filing['status'], $filing['submitted_via'], $filing['status_code'], $filing['reference_number']]);
        self::assertStringContainsString(FakeEDeklaracjeGateway::REF_ID, $send->files->read($send->refs($approvalId)['upo_file_id']));
        self::assertSame(['status' => 'done', 'filing_id' => $filing['id']], $this->db()->fetchAssociative('SELECT status, filing_id FROM obligation WHERE id=?', [$this->obligationId]));

        // Wysłany dokument: `xml_sent` bajt w bajt z jednym `DaneAutoryzujace` (SIG-2008) jako ostatnim elementem korzenia.
        $sent = $send->edeklaracje->sentDocument();
        $validator = new XsdValidator(XsdCatalog::default());
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($sent, LIBXML_NONET));
        $auth = $dom->documentElement?->lastElementChild;
        self::assertInstanceOf(\DOMElement::class, $auth);
        self::assertSame([AuthDataDocument::NS, 'DaneAutoryzujace'], [$auth->namespaceURI, $auth->localName]);
        self::assertSame(1, $dom->getElementsByTagNameNS(AuthDataDocument::NS, 'DaneAutoryzujace')->length);
        $element = (string) $dom->saveXML($auth);
        self::assertSame([], $validator->validate('<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $element, AuthDataDocument::SCHEMA));
        $start = strpos($sent, '<podp:DaneAutoryzujace');
        self::assertIsInt($start);
        $end = strpos($sent, '</podp:DaneAutoryzujace>', $start);
        self::assertIsInt($end);
        $withoutAuth = substr($sent, 0, $start) . substr($sent, $end + strlen('</podp:DaneAutoryzujace>'));
        self::assertSame($payload, $withoutAuth);
        self::assertSame([], $validator->validate($withoutAuth, VatUeBuilder::SCHEMA_UE));

        // `connector_call`: hash faktycznego żądania (koperta z danymi autoryzującymi), różny od `payload_sha256`.
        $calls = $this->db()->fetchAllAssociative("SELECT connector, operation, http_method, host, path, http_status, error_code, request_sha256, request_bytes, response_sha256, approval_id FROM connector_call WHERE connector='edeklaracje' ORDER BY sequence");
        EvidenceArtefacts::write('db/edeklaracje-send-accepted-connector_call.csv', EvidenceArtefacts::csv($calls));
        self::assertSame(['send_unsign_document', 'request_upo', 'request_upo'], array_column($calls, 'operation'));
        self::assertSame(['test-bramka.edeklaracje.gov.pl', 'test-bramka.edeklaracje.gov.pl', 'test-bramka.edeklaracje.gov.pl'], array_column($calls, 'host'));
        self::assertSame(hash('sha256', $send->edeklaracje->sentRequest()), $calls[0]['request_sha256']);
        self::assertNotSame($approval['payload_sha256'], $calls[0]['request_sha256']);
        self::assertSame($approvalId, $calls[0]['approval_id']);
        self::assertSame(1, $send->count("SELECT count(*) FROM rate_counter WHERE connector='edeklaracje' AND bucket='edeklaracje.send'"));

        // Data urodzenia i kwota N−2 tylko w pamięci workera; data urodzenia w `xml_sent` wyłącznie w `Podmiot1` (XSD VAT-UE).
        $birth = $auth->getElementsByTagNameNS(AuthDataDocument::NS, 'DataUrodzenia')->item(0)?->textContent;
        $amount = $auth->getElementsByTagNameNS(AuthDataDocument::NS, 'Kwota')->item(0)?->textContent;
        self::assertIsString($birth);
        self::assertIsString($amount);
        $needles = array_filter([$birth, $amount === '0.00' ? null : $amount], is_string(...));
        if ($amount !== '0.00') {
            self::assertStringNotContainsString($amount, $payload, 'xml_sent');
        }
        $declaration = new \DOMDocument();
        self::assertTrue($declaration->loadXML($payload, LIBXML_NONET));
        foreach (iterator_to_array($declaration->getElementsByTagNameNS('*', 'Podmiot1')) as $subject) {
            $subject->parentNode?->removeChild($subject);
        }
        $haystacks = [
            'xml_sent poza Podmiot1' => (string) $declaration->saveXML(),
            'connector_call' => json_encode($this->db()->fetchAllAssociative("SELECT * FROM connector_call WHERE connector='edeklaracje'"), JSON_THROW_ON_ERROR),
            'logs' => json_encode($send->logger->lines, JSON_THROW_ON_ERROR),
            'messenger_messages' => json_encode($this->db()->fetchAllAssociative('SELECT body, headers FROM messenger_messages'), JSON_THROW_ON_ERROR),
            'bus' => serialize($send->bus->messages),
            'audit_log' => json_encode($this->db()->fetchAllAssociative('SELECT action, details FROM audit_log'), JSON_THROW_ON_ERROR),
            'approval' => json_encode($send->approval($approvalId), JSON_THROW_ON_ERROR),
        ];
        foreach ($haystacks as $name => $haystack) {
            foreach ($needles as $needle) {
                self::assertStringNotContainsString($needle, $haystack, $name);
            }
        }
        self::assertSame(['approval.requested', 'approval.approved', 'dispatch.started', 'dispatch.submitted', 'dispatch.accepted'], $send->auditActions($approvalId));
    }
}
