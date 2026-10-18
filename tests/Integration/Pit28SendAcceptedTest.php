<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\Jpk\AuthDataDocument;
use App\Tests\Support\FakeEDeklaracjeGateway;
use App\Tests\Support\Pit28TestTools;
use App\Xml\Pit28Builder;
use App\Xml\XsdCatalog;
use App\Xml\XsdValidator;

/**
 * PIT-28(27) za 2025 przez bramkę (D-96): prośba z `FilingRequest::pit28`, zatwierdzenie, `OfficialWritesHandler`
 * i `EDeklaracjeWriter` na `FakeEDeklaracjeGateway` (`sendUnsignDocument`, `requestUPO` 200). Złożenie `accepted`
 * z `submitted_via` = `edeklaracje`, obowiązek `pit_28` roku `done`. `xml_sent` bez `DaneAutoryzujace`, wysłany dokument
 * to `xml_sent` z jednym elementem SIG-2008 na końcu korzenia. Dane fikcyjne, bez sieci.
 */
final class Pit28SendAcceptedTest extends Pit28TestCase
{
    public function testPit28AcceptedThroughGateClosesYearObligation(): void
    {
        $send = $this->pit28();
        $approvalId = $send->request->pit28(Pit28TestTools::YEAR);
        $payload = $send->payload($approvalId);
        $approval = $send->approval($approvalId);
        self::assertSame(['edeklaracje', hash('sha256', $payload)], [$approval['connector'], $approval['payload_sha256']]);
        self::assertStringNotContainsString('DaneAutoryzujace', $payload);
        $filing = $send->filing('PIT_28', '2025-01-01');
        self::assertSame(Pit28Builder::VARIANT_27, $filing['schema_version']);
        self::assertIsString($filing['totals']);
        $totals = json_decode($filing['totals'], true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($totals);
        self::assertSame(['19128', $this->seeded['ewp'], hash('sha256', $payload)], [$totals['ryczalt_due'], $totals['jpk_ewp_filing_id'], $totals['xml_sha256']]);

        $send->gate->approve($approvalId, $send->userId);
        $send->edeklaracje->load('soap-send', 'soap-upo-200');
        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        self::assertSame(1, $send->reconciler->poll());

        $filing = $send->filing('PIT_28', '2025-01-01');
        self::assertSame(['accepted', 'edeklaracje', 200, FakeEDeklaracjeGateway::REF_ID], [$filing['status'], $filing['submitted_via'], $filing['status_code'], $filing['reference_number']]);
        self::assertSame(['status' => 'done', 'filing_id' => $filing['id']], $this->db()->fetchAssociative('SELECT status, filing_id FROM obligation WHERE id=?', [$this->seeded['obligation']]));

        $sent = $send->edeklaracje->sentDocument();
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($sent, LIBXML_NONET));
        $auth = $dom->documentElement?->lastElementChild;
        self::assertInstanceOf(\DOMElement::class, $auth);
        self::assertSame([AuthDataDocument::NS, 'DaneAutoryzujace'], [$auth->namespaceURI, $auth->localName]);
        $start = strpos($sent, '<podp:DaneAutoryzujace');
        self::assertIsInt($start);
        $end = strpos($sent, '</podp:DaneAutoryzujace>', $start);
        self::assertIsInt($end);
        $withoutAuth = substr($sent, 0, $start) . substr($sent, $end + strlen('</podp:DaneAutoryzujace>'));
        self::assertSame($payload, $withoutAuth);
        self::assertSame([], (new XsdValidator(XsdCatalog::default()))->validate($withoutAuth, Pit28Builder::SCHEMA_27));
    }

    public function testSecondRequestAfterAcceptanceRefuses(): void
    {
        $send = $this->pit28();
        $approvalId = $send->request->pit28(Pit28TestTools::YEAR);
        $send->gate->approve($approvalId, $send->userId);
        $send->edeklaracje->load('soap-send', 'soap-upo-200');
        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        self::assertSame(1, $send->reconciler->poll());
        $this->expectExceptionMessage('PIT-28 za 2025 jest przyjęty; korektę składasz ręcznie (D-96).');
        $send->request->pit28(Pit28TestTools::YEAR);
    }
}
