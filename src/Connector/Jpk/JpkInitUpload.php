<?php

declare(strict_types=1);

namespace App\Connector\Jpk;

use App\Xml\XsdCatalog;
use App\Xml\XsdValidator;

/**
 * Metadane `InitUpload` (docs/modules/jpk.md, „Przepływ wysyłki”, krok 8): `DocumentType` i `Version` z konfiguracji
 * `jpk.init_upload`, `FormCode` z `kodSystemowy` i `wersjaSchemy` dokumentu, `FileName` według
 * `[a-zA-Z0-9_.-]{5,55}`, `ContentLength`, `HashValue` (SHA-256 dokumentu w Base64), szyfrowanie, lista części
 * i `AuthData`. Bez podpisu XAdES obok `AuthData` (kod 136). Walidacja XSD lokalnie przed wysyłką; bez schematu
 * w `resources/xsd` metadane nie powstają.
 */
final readonly class JpkInitUpload
{
    public const SCHEMA = 'InitUpload.xsd';
    public const NS = 'http://e-dokumenty.mf.gov.pl';
    public const FILE_NAME = '/^[a-zA-Z0-9_.-]{5,55}$/D';
    private const XMLDSIG = 'http://www.w3.org/2000/09/xmldsig#';

    /** @param array{document_type:string,version:string,max_bytes:int} $settings konfiguracja `jpk.init_upload` */
    public function __construct(private XsdCatalog $catalog, private XsdValidator $validator, private array $settings) {}

    /** Czy schemat metadanych jest w `resources/xsd`; bez niego wysyłka czeka (`xsd_missing`). */
    public function schemaAvailable(): bool
    {
        return $this->catalog->has(self::SCHEMA);
    }

    /**
     * `FormCode` z nagłówka dokumentu JPK: atrybuty `kodSystemowy` i `wersjaSchemy` oraz wartość `KodFormularza`.
     *
     * @return array{system_code:string,schema_version:string,value:string}
     */
    public static function formCode(string $document): array
    {
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($document, LIBXML_NONET)) {
            throw JpkException::of(JpkError::MetadataInvalid);
        }
        $node = $dom->getElementsByTagNameNS('*', 'KodFormularza')->item(0);
        if (!$node instanceof \DOMElement) {
            throw JpkException::of(JpkError::MetadataInvalid);
        }
        $system = $node->getAttribute('kodSystemowy');
        $version = $node->getAttribute('wersjaSchemy');
        $value = trim($node->textContent);
        if ($system === '' || $version === '' || $value === '') {
            throw JpkException::of(JpkError::MetadataInvalid);
        }
        return ['system_code' => $system, 'schema_version' => $version, 'value' => $value];
    }

    /**
     * @param array{system_code:string,schema_version:string,value:string} $formCode
     * @param string $authData zaszyfrowany kluczem pliku `AuthData` w Base64
     */
    public function build(JpkSealedFile $file, array $formCode, string $fileName, string $authData): string
    {
        if (preg_match(self::FILE_NAME, $fileName) !== 1) {
            throw JpkException::of(JpkError::MetadataInvalid);
        }
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $root = $dom->createElementNS(self::NS, 'InitUpload');
        $dom->appendChild($root);
        self::child($root, 'DocumentType', $this->settings['document_type']);
        self::child($root, 'Version', $this->settings['version']);
        $key = self::child($root, 'EncryptionKey', $file->encryptedKeyBase64);
        foreach (['algorithm' => 'RSA', 'mode' => 'ECB', 'padding' => 'PKCS#1', 'encoding' => 'Base64'] as $name => $value) {
            $key->setAttribute($name, $value);
        }
        $document = self::child(self::child($root, 'DocumentList'), 'Document');
        $form = self::child($document, 'FormCode', $formCode['value']);
        $form->setAttribute('systemCode', $formCode['system_code']);
        $form->setAttribute('schemaVersion', $formCode['schema_version']);
        self::child($document, 'FileName', $fileName);
        self::child($document, 'ContentLength', (string) $file->documentLength);
        $hash = self::child($document, 'HashValue', $file->documentSha256Base64);
        $hash->setAttribute('algorithm', 'SHA-256');
        $hash->setAttribute('encoding', 'Base64');
        $list = self::child($document, 'FileSignatureList');
        $list->setAttribute('filesNumber', (string) count($file->parts));
        $split = self::child(self::child($list, 'Packaging'), 'SplitZip');
        $split->setAttribute('type', 'split');
        $split->setAttribute('mode', 'zip');
        $aes = self::child(self::child($list, 'Encryption'), 'AES');
        foreach (['size' => '256', 'block' => '16', 'mode' => 'CBC', 'padding' => 'PKCS#7'] as $name => $value) {
            $aes->setAttribute($name, $value);
        }
        $iv = self::child($aes, 'IV', $file->ivBase64());
        $iv->setAttribute('bytes', '16');
        $iv->setAttribute('encoding', 'Base64');
        foreach ($file->parts as $part) {
            $signature = self::child($list, 'FileSignature');
            self::child($signature, 'OrdinalNumber', (string) $part->ordinal);
            self::child($signature, 'FileName', $part->fileName);
            self::child($signature, 'ContentLength', (string) $part->length());
            $md5 = self::child($signature, 'HashValue', $part->md5Base64);
            $md5->setAttribute('algorithm', 'MD5');
            $md5->setAttribute('encoding', 'Base64');
        }
        self::child($root, 'AuthData', $authData);
        $xml = $dom->saveXML();
        if ($xml === false) {
            throw JpkException::of(JpkError::MetadataInvalid);
        }
        $this->check($xml);
        return $xml;
    }

    /** Kontrole lokalne metadanych: rozmiar, `AuthData` bez podpisu XAdES (kod 136) i XSD `InitUpload`. */
    public function check(string $xml): void
    {
        if (strlen($xml) > $this->settings['max_bytes']) {
            throw JpkException::of(JpkError::MetadataInvalid);
        }
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xml, LIBXML_NONET)) {
            throw JpkException::of(JpkError::MetadataInvalid);
        }
        if ($dom->getElementsByTagNameNS(self::NS, 'AuthData')->length === 1 && $dom->getElementsByTagNameNS(self::XMLDSIG, 'Signature')->length > 0) {
            throw JpkException::of(JpkError::SignatureWithAuthData);
        }
        if (!$this->schemaAvailable()) {
            throw JpkException::schemaMissing(self::SCHEMA);
        }
        if ($this->validator->validate($xml, self::SCHEMA) !== []) {
            throw JpkException::of(JpkError::MetadataInvalid);
        }
    }

    private static function child(\DOMElement $parent, string $name, ?string $value = null): \DOMElement
    {
        $document = $parent->ownerDocument ?? throw new \LogicException('Element bez dokumentu.');
        $element = $document->createElementNS(self::NS, $name);
        if ($value !== null) {
            $element->appendChild($document->createTextNode($value));
        }
        $parent->appendChild($element);
        return $element;
    }
}
