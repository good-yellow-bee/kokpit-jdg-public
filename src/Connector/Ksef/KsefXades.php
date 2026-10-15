<?php

declare(strict_types=1);

namespace App\Connector\Ksef;

/**
 * Podpis XAdES-BES `AuthTokenRequest` do `POST /auth/xades-signature` (docs/modules/ksef.md, „Uwierzytelnienie”;
 * D-72): podpis otaczany z referencją do dokumentu (enveloped-signature, exc-c14n) i do `SignedProperties`,
 * RSA-SHA256, SHA-256 w skrótach, certyfikat w `KeyInfo` i `SigningCertificate`. Kontekst to NIP, podmiot to podmiot
 * certyfikatu (`certificateSubject`). Służy certyfikatowi KSeF typ 1 (`ksef_auth_cert`) i pieczęci testowej na TE.
 */
final class KsefXades
{
    private const DS = 'http://www.w3.org/2000/09/xmldsig#';
    private const XADES = 'http://uri.etsi.org/01903/v1.3.2#';
    private const AUTH = 'http://ksef.mf.gov.pl/auth/token/2.0';
    private const C14N = 'http://www.w3.org/2001/10/xml-exc-c14n#';
    private const SHA256 = 'http://www.w3.org/2001/04/xmlenc#sha256';

    /** Podpisany `AuthTokenRequest` dla challenge i NIP kontekstu. */
    public static function authTokenRequest(string $challenge, string $nip, \OpenSSLAsymmetricKey $key, \OpenSSLCertificate $certificate, \DateTimeImmutable $now): string
    {
        return self::signed(self::request($challenge, $nip), $key, $certificate, $now);
    }

    private static function request(string $challenge, string $nip): \DOMDocument
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $root = $doc->appendChild($doc->createElementNS(self::AUTH, 'AuthTokenRequest'));
        $root->appendChild($doc->createElementNS(self::AUTH, 'Challenge', $challenge));
        $root->appendChild($doc->createElementNS(self::AUTH, 'ContextIdentifier'))->appendChild($doc->createElementNS(self::AUTH, 'Nip', $nip));
        $root->appendChild($doc->createElementNS(self::AUTH, 'SubjectIdentifierType', 'certificateSubject'));
        return $doc;
    }

    /**
     * Podpis XAdES-BES otaczany: referencja do dokumentu (enveloped-signature, exc-c14n) i do `SignedProperties`,
     * RSA-SHA256, SHA-256 w skrótach, certyfikat w `KeyInfo` i `SigningCertificate`.
     */
    private static function signed(\DOMDocument $doc, \OpenSSLAsymmetricKey $key, \OpenSSLCertificate $certificate, \DateTimeImmutable $now): string
    {
        $root = $doc->documentElement ?? throw new \LogicException('Brak korzenia.');
        openssl_x509_export($certificate, $pem);
        $der = (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', (string) $pem), true);
        $info = openssl_x509_parse($certificate);
        $issuer = is_array($info) && is_array($info['issuer'] ?? null) ? $info['issuer'] : [];
        $documentDigest = base64_encode(hash('sha256', (string) $root->C14N(true, false), true));
        $el = static function (string $namespace, string $name, ?string $text = null) use ($doc): \DOMElement {
            $qualified = ($namespace === self::DS ? 'ds:' : 'xades:') . $name;
            return $text === null ? $doc->createElementNS($namespace, $qualified) : $doc->createElementNS($namespace, $qualified, $text);
        };
        $algorithm = static function (\DOMNode $parent, string $name, string $algorithm) use ($el): void {
            $node = $parent->appendChild($el(self::DS, $name));
            if ($node instanceof \DOMElement) {
                $node->setAttribute('Algorithm', $algorithm);
            }
        };
        $signature = $root->appendChild($el(self::DS, 'Signature'));
        $signature = self::element($signature);
        $signature->setAttribute('Id', 'Signature');
        $signedInfo = $signature->appendChild($el(self::DS, 'SignedInfo'));
        $algorithm($signedInfo, 'CanonicalizationMethod', self::C14N);
        $algorithm($signedInfo, 'SignatureMethod', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');
        $document = $signedInfo->appendChild($el(self::DS, 'Reference'));
        $document = self::element($document);
        $document->setAttribute('URI', '');
        $transforms = $document->appendChild($el(self::DS, 'Transforms'));
        $algorithm($transforms, 'Transform', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
        $algorithm($transforms, 'Transform', self::C14N);
        $algorithm($document, 'DigestMethod', self::SHA256);
        $document->appendChild($el(self::DS, 'DigestValue', $documentDigest));
        $properties = $signedInfo->appendChild($el(self::DS, 'Reference'));
        $properties = self::element($properties);
        $properties->setAttribute('Type', 'http://uri.etsi.org/01903#SignedProperties');
        $properties->setAttribute('URI', '#SignedProperties');
        $algorithm($properties->appendChild($el(self::DS, 'Transforms')), 'Transform', self::C14N);
        $algorithm($properties, 'DigestMethod', self::SHA256);
        $propertiesDigest = $properties->appendChild($el(self::DS, 'DigestValue'));
        $value = $signature->appendChild($el(self::DS, 'SignatureValue'));
        $signature->appendChild($el(self::DS, 'KeyInfo'))->appendChild($el(self::DS, 'X509Data'))->appendChild($el(self::DS, 'X509Certificate', base64_encode($der)));
        $qualifying = $signature->appendChild($el(self::DS, 'Object'))->appendChild($el(self::XADES, 'QualifyingProperties'));
        $qualifying = self::element($qualifying);
        $qualifying->setAttribute('Target', '#Signature');
        $signed = $qualifying->appendChild($el(self::XADES, 'SignedProperties'));
        $signed = self::element($signed);
        $signed->setAttribute('Id', 'SignedProperties');
        $signatureProperties = $signed->appendChild($el(self::XADES, 'SignedSignatureProperties'));
        $signatureProperties->appendChild($el(self::XADES, 'SigningTime', $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')));
        $cert = $signatureProperties->appendChild($el(self::XADES, 'SigningCertificate'))->appendChild($el(self::XADES, 'Cert'));
        $digest = $cert->appendChild($el(self::XADES, 'CertDigest'));
        $algorithm($digest, 'DigestMethod', self::SHA256);
        $digest->appendChild($el(self::DS, 'DigestValue', base64_encode(hash('sha256', $der, true))));
        $serial = $cert->appendChild($el(self::XADES, 'IssuerSerial'));
        $issuerName = implode(',', array_reverse(array_map(static fn(string $name, mixed $part): string => $name . '=' . (is_string($part) ? $part : ''), array_keys($issuer), $issuer)));
        $serial->appendChild($el(self::DS, 'X509IssuerName', $issuerName));
        $serial->appendChild($el(self::DS, 'X509SerialNumber', is_array($info) && is_string($info['serialNumber'] ?? null) ? $info['serialNumber'] : '0'));
        $propertiesDigest->appendChild($doc->createTextNode(base64_encode(hash('sha256', (string) $signed->C14N(true, false), true))));
        if (!openssl_sign((string) $signedInfo->C14N(true, false), $raw, $key, OPENSSL_ALGO_SHA256) || !is_string($raw)) {
            throw new KsefException(KsefError::RequestRefused, 'xades_sign_failed');
        }
        $value->appendChild($doc->createTextNode(base64_encode($raw)));
        return (string) $doc->saveXML();
    }

    private static function element(\DOMNode $node): \DOMElement
    {
        return $node instanceof \DOMElement ? $node : throw new \LogicException('Oczekiwany element XML.');
    }
}
