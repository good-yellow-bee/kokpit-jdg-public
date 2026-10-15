<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Connector\Ksef\KsefApi;
use App\Connector\Ksef\KsefError;
use App\Connector\Ksef\KsefException;
use Symfony\Component\Clock\ClockInterface;

/**
 * Dane testowe KSeF TE dla przebiegu F3.5 (D-70), tylko na `api-test.ksef.mf.gov.pl` i tylko w `@group live`:
 * losowy fikcyjny NIP z poprawną sumą kontrolną (testowa JDG przebiegu, bez kolizji P_2 z innymi użytkownikami TE),
 * samopodpisana pieczęć (`organizationIdentifier` = `VATPL-<NIP>`, RSA 2048, ważna 2 dni), podpis XAdES-BES
 * `AuthTokenRequest` na `POST /auth/xades-signature`, potem token KSeF z `InvoiceWrite` i `InvoiceRead`
 * (`POST /tokens`). TE przyjmuje certyfikaty samopodpisane ([uwierzytelnianie](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/uwierzytelnianie.md)).
 * Klucz, certyfikat i token żyją tylko w pamięci procesu; do repo i logów trafiają najwyżej numery referencyjne.
 */
final class KsefTeProvision
{
    private const DS = 'http://www.w3.org/2000/09/xmldsig#';
    private const XADES = 'http://uri.etsi.org/01903/v1.3.2#';
    private const AUTH = 'http://ksef.mf.gov.pl/auth/token/2.0';
    private const C14N = 'http://www.w3.org/2001/10/xml-exc-c14n#';
    private const SHA256 = 'http://www.w3.org/2001/04/xmlenc#sha256';
    private const POLLS = 30;

    public string $tokenReference = '';
    public string $certificateNotAfter = '';

    public function __construct(private readonly KsefApi $api, private readonly ClockInterface $clock) {}

    /** Losowy NIP z poprawną sumą kontrolną (wagi 6, 5, 7, 2, 3, 4, 5, 6, 7), fikcyjny dla przebiegu. */
    public static function randomNip(): string
    {
        do {
            $digits = [random_int(1, 9)];
            for ($i = 1; $i < 9; ++$i) {
                $digits[] = random_int(0, 9);
            }
            $sum = 0;
            foreach ([6, 5, 7, 2, 3, 4, 5, 6, 7] as $i => $weight) {
                $sum += $weight * $digits[$i];
            }
        } while ($sum % 11 === 10);
        return implode('', $digits) . ($sum % 11);
    }

    /** Token KSeF z `InvoiceWrite` i `InvoiceRead` w kontekście `$nip`, aktywny. */
    public function token(string $nip): string
    {
        $access = $this->xadesAccessToken($nip);
        $generated = $this->api->json(null, 'provision_token', 'ksef.default', 'POST', '/tokens', ['permissions' => ['InvoiceWrite', 'InvoiceRead'], 'description' => 'Fikcyjny token testowy kokpitu (F3.5)'], $access);
        $token = is_string($generated['token'] ?? null) ? $generated['token'] : throw new KsefException(KsefError::RequestRefused, 'token_missing');
        $this->tokenReference = is_string($generated['referenceNumber'] ?? null) ? $generated['referenceNumber'] : '';
        for ($poll = 0; $poll < self::POLLS; ++$poll) {
            $status = $this->api->json(null, 'provision_token_status', 'ksef.default', 'GET', '/tokens/' . $this->tokenReference, null, $access);
            if (($status['status'] ?? null) === 'Active') {
                return $token;
            }
            $this->clock->sleep(2);
        }
        throw new KsefException(KsefError::RequestRefused, 'token_not_active');
    }

    /** accessToken po uwierzytelnieniu XAdES samopodpisaną pieczęcią dla `$nip`. */
    public function xadesAccessToken(string $nip): string
    {
        [$key, $certificate] = $this->seal($nip);
        $challenge = $this->api->json(null, 'provision_challenge', 'ksef.challenge', 'POST', '/auth/challenge', []);
        $signed = self::signed(self::request(is_string($challenge['challenge'] ?? null) ? $challenge['challenge'] : throw new KsefException(KsefError::RequestRefused, 'challenge_invalid'), $nip), $key, $certificate, $this->clock->now());
        $started = json_decode($this->api->request(null, 'provision_xades', 'ksef.default', 'POST', '/auth/xades-signature', $signed)->getContent(false), true, 8, JSON_THROW_ON_ERROR);
        $operation = is_array($started) && is_array($started['authenticationToken'] ?? null) && is_string($started['authenticationToken']['token'] ?? null) ? $started['authenticationToken']['token'] : throw new KsefException(KsefError::RequestRefused, 'xades_without_token');
        $reference = is_string($started['referenceNumber'] ?? null) ? $started['referenceNumber'] : '';
        for ($poll = 0;; ++$poll) {
            $status = $this->api->json(null, 'provision_auth_status', 'ksef.default', 'GET', '/auth/' . $reference, null, $operation);
            $code = is_array($status['status'] ?? null) ? ($status['status']['code'] ?? null) : null;
            if ($code === 200) {
                break;
            }
            if ($code !== 100 || $poll >= self::POLLS) {
                throw new KsefException(KsefError::RequestRefused, 'xades_auth_' . (is_int($code) ? $code : 'unknown'));
            }
            $this->clock->sleep(1);
        }
        $redeemed = $this->api->json(null, 'provision_redeem', 'ksef.default', 'POST', '/auth/token/redeem', null, $operation);
        $access = is_array($redeemed['accessToken'] ?? null) ? $redeemed['accessToken'] : [];
        return is_string($access['token'] ?? null) ? $access['token'] : throw new KsefException(KsefError::RequestRefused, 'access_token_missing');
    }

    /** @return array{\OpenSSLAsymmetricKey,\OpenSSLCertificate} */
    private function seal(string $nip): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new \RuntimeException('Brak klucza RSA.');
        }
        $dn = ['countryName' => 'PL', 'organizationName' => 'Fikcyjna JDG testowa (fictional)', 'organizationIdentifier' => 'VATPL-' . $nip, 'commonName' => 'Fikcyjna pieczec testowa'];
        $csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
        $certificate = $csr instanceof \OpenSSLCertificateSigningRequest ? openssl_csr_sign($csr, null, $key, 2, ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX)) : false;
        if ($certificate === false) {
            throw new \RuntimeException('Brak certyfikatu samopodpisanego.');
        }
        $info = openssl_x509_parse($certificate);
        $this->certificateNotAfter = is_array($info) && is_int($info['validTo_time_t'] ?? null) ? gmdate('Y-m-d\TH:i:s\Z', $info['validTo_time_t']) : '';
        return [$key, $certificate];
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
        assert($signature instanceof \DOMElement);
        $signature->setAttribute('Id', 'Signature');
        $signedInfo = $signature->appendChild($el(self::DS, 'SignedInfo'));
        $algorithm($signedInfo, 'CanonicalizationMethod', self::C14N);
        $algorithm($signedInfo, 'SignatureMethod', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');
        $document = $signedInfo->appendChild($el(self::DS, 'Reference'));
        assert($document instanceof \DOMElement);
        $document->setAttribute('URI', '');
        $transforms = $document->appendChild($el(self::DS, 'Transforms'));
        $algorithm($transforms, 'Transform', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
        $algorithm($transforms, 'Transform', self::C14N);
        $algorithm($document, 'DigestMethod', self::SHA256);
        $document->appendChild($el(self::DS, 'DigestValue', $documentDigest));
        $properties = $signedInfo->appendChild($el(self::DS, 'Reference'));
        assert($properties instanceof \DOMElement);
        $properties->setAttribute('Type', 'http://uri.etsi.org/01903#SignedProperties');
        $properties->setAttribute('URI', '#SignedProperties');
        $algorithm($properties->appendChild($el(self::DS, 'Transforms')), 'Transform', self::C14N);
        $algorithm($properties, 'DigestMethod', self::SHA256);
        $propertiesDigest = $properties->appendChild($el(self::DS, 'DigestValue'));
        $value = $signature->appendChild($el(self::DS, 'SignatureValue'));
        $signature->appendChild($el(self::DS, 'KeyInfo'))->appendChild($el(self::DS, 'X509Data'))->appendChild($el(self::DS, 'X509Certificate', base64_encode($der)));
        $qualifying = $signature->appendChild($el(self::DS, 'Object'))->appendChild($el(self::XADES, 'QualifyingProperties'));
        assert($qualifying instanceof \DOMElement);
        $qualifying->setAttribute('Target', '#Signature');
        $signed = $qualifying->appendChild($el(self::XADES, 'SignedProperties'));
        assert($signed instanceof \DOMElement);
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
            throw new \RuntimeException('Podpis XAdES nie powstał.');
        }
        $value->appendChild($doc->createTextNode(base64_encode($raw)));
        return (string) $doc->saveXML();
    }
}
