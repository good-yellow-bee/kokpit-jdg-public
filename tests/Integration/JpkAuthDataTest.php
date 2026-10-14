<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\Jpk\AuthDataDocument;
use App\Tests\Support\JpkSendTestTools;
use App\Xml\XsdCatalog;
use App\Xml\XsdValidator;

/**
 * Kryterium `jpk-auth-data` (F5.1): `AuthData` z fikcyjnego `auth_data` sejfu testowego, zgodne z oficjalnym XSD
 * SIG-2008 i zaszyfrowane kluczem pliku; zero wystąpień daty urodzenia i kwoty N−2 w `xml_sent`, `connector_call`,
 * logach i `messenger_messages`. Wiersz jest wstrzymany, dopóki schematu MF nie ma w `resources/xsd` (D-57); po jego
 * dostarczeniu ten test pokaże też konflikt z `etd:DataUrodzenia` w `Podmiot1` JPK_EWP(4) z F2.4 (D-57).
 */
final class JpkAuthDataTest extends JpkSendTestCase
{
    public function testAuthDataIsValidEncryptedAndNeverStoredInClear(): void
    {
        if (!JpkSendTestTools::official()) {
            self::markTestSkipped('Stop: brak oficjalnych XSD InitUpload i SIG-2008 w resources/xsd (docs/phases/f5-jpk.md, „Wejścia”).');
        }
        $send = $this->send(catalog: XsdCatalog::default());
        $plain = $send->authData->build('test');
        self::assertSame([], (new XsdValidator(XsdCatalog::default()))->validate($plain, AuthDataDocument::SCHEMA));
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($plain, LIBXML_NONET));
        $birth = $dom->getElementsByTagNameNS(AuthDataDocument::NS, 'DataUrodzenia')->item(0)?->textContent;
        $amount = $dom->getElementsByTagNameNS(AuthDataDocument::NS, 'Kwota')->item(0)?->textContent;
        self::assertIsString($birth);
        self::assertIsString($amount);

        $approvalId = $send->approved();
        $send->loadUpload();
        self::assertSame('submitted', $send->handler->dispatch($approvalId));
        $metadata = $send->fake->metadata();
        self::assertStringNotContainsString($birth, $metadata);

        $needles = array_filter([$birth, $amount === '0.00' ? null : $amount], is_string(...));
        $haystacks = [
            'xml_sent' => $send->payload($approvalId),
            'connector_call' => json_encode($this->db()->fetchAllAssociative("SELECT * FROM connector_call WHERE connector='jpk'"), JSON_THROW_ON_ERROR),
            'logs' => json_encode($send->logger->lines, JSON_THROW_ON_ERROR),
            'messenger_messages' => json_encode($this->db()->fetchAllAssociative('SELECT body, headers FROM messenger_messages'), JSON_THROW_ON_ERROR),
        ];
        foreach ($haystacks as $name => $haystack) {
            foreach ($needles as $needle) {
                self::assertStringNotContainsString($needle, $haystack, $name);
            }
        }
    }
}
