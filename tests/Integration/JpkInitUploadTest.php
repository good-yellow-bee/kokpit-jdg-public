<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\Jpk\JpkInitUpload;
use App\Tests\Support\JpkSendTestTools;
use App\Xml\XsdCatalog;
use App\Xml\XsdValidator;

/**
 * Kryterium `jpk-init-upload` (F5.1): metadane zgodne z oficjalnym XSD `InitUpload`, do 100 KB, `FormCode`
 * z dokumentu, `FileName` według wzorca, `AuthData` bez XAdES. Wiersz jest wstrzymany („stop” w tabeli „Wejścia”),
 * dopóki schematu MF nie ma w `resources/xsd`; zaślepka testowa go nie zastępuje (D-57).
 */
final class JpkInitUploadTest extends JpkSendTestCase
{
    public function testMetadataMatchesOfficialSchema(): void
    {
        if (!JpkSendTestTools::official()) {
            self::markTestSkipped('Stop: brak oficjalnych XSD InitUpload i SIG-2008 w resources/xsd (docs/phases/f5-jpk.md, „Wejścia”).');
        }
        $send = $this->send(catalog: XsdCatalog::default());
        $approvalId = $send->approved();
        $send->loadUpload();
        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        $metadata = $send->fake->metadata();

        self::assertLessThanOrEqual(102400, strlen($metadata));
        self::assertSame([], (new XsdValidator(XsdCatalog::default()))->validate($metadata, JpkInitUpload::SCHEMA));
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($metadata, LIBXML_NONET));
        $form = $dom->getElementsByTagNameNS(JpkInitUpload::NS, 'FormCode')->item(0);
        self::assertInstanceOf(\DOMElement::class, $form);
        $expected = JpkInitUpload::formCode($send->payload($approvalId));
        self::assertSame([$expected['system_code'], $expected['schema_version'], $expected['value']], [$form->getAttribute('systemCode'), $form->getAttribute('schemaVersion'), $form->textContent]);
        $fileName = $dom->getElementsByTagNameNS(JpkInitUpload::NS, 'FileName')->item(0);
        self::assertInstanceOf(\DOMElement::class, $fileName);
        self::assertMatchesRegularExpression(JpkInitUpload::FILE_NAME, $fileName->textContent);
        self::assertSame(1, $dom->getElementsByTagNameNS(JpkInitUpload::NS, 'AuthData')->length);
        self::assertSame(0, $dom->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Signature')->length);
    }
}
