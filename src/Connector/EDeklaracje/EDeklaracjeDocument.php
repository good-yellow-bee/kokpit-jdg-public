<?php

declare(strict_types=1);

namespace App\Connector\EDeklaracje;

use App\Connector\Jpk\AuthDataDocument;
use App\Xml\VatUeBuilder;
use App\Xml\XsdValidator;

/**
 * Deklaracja z danymi autoryzującymi tylko w pamięci workera (docs/modules/jpk.md, „Bramka e-Deklaracji (SOAP)”, D-66).
 * `DaneAutoryzujace` wchodzi jako ostatni element `Deklaracja` przez wstawienie przed zamykającym znacznikiem korzenia:
 * bajty `xml_sent` zostają nienaruszone, bez ponownej serializacji. Opublikowane XSD VAT-UE(5) i VAT-UEK(5) nie deklarują
 * `DaneAutoryzujace`, więc kontrola w pamięci to: deklaracja (bajty `xml_sent`) zgodna ze swoim XSD, element zgodny
 * z SIG-2008, dokładnie jeden i ostatni w korzeniu; odpowiedź bramki testowej potwierdza to w F5.6 (401 → nowy D-xx).
 */
final readonly class EDeklaracjeDocument
{
    private const SCHEMAS = [VatUeBuilder::TNS_UE => VatUeBuilder::SCHEMA_UE, VatUeBuilder::TNS_UEK => VatUeBuilder::SCHEMA_UEK];
    private const ROOT_CLOSE = '</Deklaracja>';

    public function __construct(private XsdValidator $validator) {}

    /**
     * Deklaracja z wstawionym elementem `podp:DaneAutoryzujace`; błędy nie cytują wartości osobowych.
     *
     * @throws EDeklaracjeException `document_invalid`
     */
    public function authorize(string $xmlSent, string $authElement): string
    {
        $declaration = new \DOMDocument();
        if (!@$declaration->loadXML($xmlSent, LIBXML_NONET) || $declaration->documentElement === null) {
            throw EDeklaracjeException::of(EDeklaracjeError::DocumentInvalid);
        }
        $root = $declaration->documentElement;
        $schema = self::SCHEMAS[(string) $root->namespaceURI] ?? null;
        if ($schema === null || $root->localName !== 'Deklaracja' || $root->prefix !== '' || $this->validator->validate($xmlSent, $schema) !== []) {
            throw EDeklaracjeException::of(EDeklaracjeError::DocumentInvalid);
        }
        if ($declaration->getElementsByTagNameNS(AuthDataDocument::NS, 'DaneAutoryzujace')->length !== 0) {
            // `xml_sent` nigdy nie zawiera danych autoryzujących.
            throw EDeklaracjeException::of(EDeklaracjeError::DocumentInvalid);
        }
        if ($this->validator->validate('<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $authElement, AuthDataDocument::SCHEMA) !== []) {
            throw EDeklaracjeException::of(EDeklaracjeError::DocumentInvalid);
        }
        $close = strrpos($xmlSent, self::ROOT_CLOSE);
        if ($close === false || trim(substr($xmlSent, $close + strlen(self::ROOT_CLOSE))) !== '') {
            throw EDeklaracjeException::of(EDeklaracjeError::DocumentInvalid);
        }
        $authorized = substr($xmlSent, 0, $close) . $authElement . substr($xmlSent, $close);
        $check = new \DOMDocument();
        if (!@$check->loadXML($authorized, LIBXML_NONET) || $check->documentElement === null) {
            throw EDeklaracjeException::of(EDeklaracjeError::DocumentInvalid);
        }
        $last = $check->documentElement->lastElementChild;
        if ($last === null || $last->namespaceURI !== AuthDataDocument::NS || $last->localName !== 'DaneAutoryzujace' || $check->getElementsByTagNameNS(AuthDataDocument::NS, 'DaneAutoryzujace')->length !== 1) {
            throw EDeklaracjeException::of(EDeklaracjeError::DocumentInvalid);
        }
        return $authorized;
    }
}
