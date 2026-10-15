<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\Jpk\JpkException;
use App\Connector\Jpk\JpkInitUpload;
use App\Xml\XsdCatalog;
use App\Xml\XsdValidator;

/**
 * Kryterium `jpk-init-upload` (F5.1): metadane zgodne z oficjalnym XSD `initupload.xsd` z `resources/xsd`, do 100 KB,
 * `FormCode` z dokumentu, `FileName` według wzorca, `AuthData` bez XAdES. Opublikowany XSD nie deklaruje `AuthData`
 * (D-63): schemat waliduje metadane bez tego elementu, a `AuthData` jest jedynym dodatkiem, ostatnim dzieckiem.
 */
final class JpkInitUploadTest extends JpkSendTestCase
{
    public function testMetadataMatchesOfficialSchema(): void
    {
        $send = $this->send(catalog: XsdCatalog::default());

        $approvalId = $send->approved();
        $send->loadUpload();
        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        $metadata = $send->fake->metadata();

        self::assertLessThanOrEqual(102400, strlen($metadata));
        $send->initUpload->check($metadata);
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($metadata, LIBXML_NONET));
        $root = $dom->documentElement;
        self::assertInstanceOf(\DOMElement::class, $root);
        $authData = $root->lastElementChild;
        self::assertInstanceOf(\DOMElement::class, $authData);
        self::assertSame([JpkInitUpload::NS, 'AuthData'], [$authData->namespaceURI, $authData->localName]);
        self::assertNotFalse(base64_decode($authData->textContent, true));
        // Bez `AuthData` metadane są zgodne z oficjalnym XSD; z nim XSD z 2016 r. ich nie przyjmuje (D-63).
        $validator = new XsdValidator(XsdCatalog::default());
        self::assertNotSame([], $validator->validate($metadata, JpkInitUpload::SCHEMA));
        $schemaPart = clone $dom;
        $schemaRoot = $schemaPart->documentElement;
        self::assertInstanceOf(\DOMElement::class, $schemaRoot);
        $schemaAuthData = $schemaRoot->lastElementChild;
        self::assertInstanceOf(\DOMElement::class, $schemaAuthData);
        $schemaRoot->removeChild($schemaAuthData);
        self::assertSame([], $validator->validate((string) $schemaPart->saveXML(), JpkInitUpload::SCHEMA));
        $form = $dom->getElementsByTagNameNS(JpkInitUpload::NS, 'FormCode')->item(0);
        self::assertInstanceOf(\DOMElement::class, $form);
        $expected = JpkInitUpload::formCode($send->payload($approvalId));
        self::assertSame([$expected['system_code'], $expected['schema_version'], $expected['value']], [$form->getAttribute('systemCode'), $form->getAttribute('schemaVersion'), $form->textContent]);
        $fileName = $dom->getElementsByTagNameNS(JpkInitUpload::NS, 'FileName')->item(0);
        self::assertInstanceOf(\DOMElement::class, $fileName);
        self::assertMatchesRegularExpression(JpkInitUpload::FILE_NAME, $fileName->textContent);
        self::assertSame(1, $dom->getElementsByTagNameNS(JpkInitUpload::NS, 'AuthData')->length);
        self::assertSame(0, $dom->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Signature')->length);

        // `AuthData` spoza XSD ma ściśle określone miejsce i postać; każde odstępstwo to `metadata_invalid` (D-63).
        $authDataXml = (string) $dom->saveXML($authData);
        $variants = [
            'przed DocumentList' => str_replace([$authDataXml, '<DocumentList>'], ['', $authDataXml . '<DocumentList>'], $metadata),
            'dwa razy' => str_replace($authDataXml, $authDataXml . $authDataXml, $metadata),
            'bez Base64' => str_replace($authDataXml, '<AuthData>fikcyjne dane</AuthData>', $metadata),
            'z atrybutem' => str_replace('<AuthData>', '<AuthData encoding="Base64">', $metadata),
        ];
        foreach ($variants as $name => $variant) {
            self::assertNotSame($metadata, $variant, $name);
            try {
                $send->initUpload->check($variant);
                self::fail('Metadane przyjęte: ' . $name);
            } catch (JpkException $exception) {
                self::assertSame('metadata_invalid', $exception->resultCode, $name);
            }
        }
    }
}
