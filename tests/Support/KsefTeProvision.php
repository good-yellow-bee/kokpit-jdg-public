<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Connector\Ksef\KsefApi;
use App\Connector\Ksef\KsefError;
use App\Connector\Ksef\KsefException;
use App\Connector\Ksef\KsefXades;
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

    /**
     * Token KSeF z uprawnieniami `$permissions` w kontekście `$nip`, aktywny.
     *
     * @param list<string> $permissions
     */
    public function token(string $nip, array $permissions = ['InvoiceWrite', 'InvoiceRead']): string
    {
        $access = $this->xadesAccessToken($nip);
        $generated = $this->api->json(null, 'provision_token', 'ksef.default', 'POST', '/tokens', ['permissions' => $permissions, 'description' => 'Fikcyjny token testowy kokpitu (' . implode(', ', $permissions) . ')'], $access);
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
        $signed = KsefXades::authTokenRequest(is_string($challenge['challenge'] ?? null) ? $challenge['challenge'] : throw new KsefException(KsefError::RequestRefused, 'challenge_invalid'), $nip, $key, $certificate, $this->clock->now());
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

    /** Pieczęć jako PEM: certyfikat i klucz prywatny, postać sekretu `ksef_auth_cert` (D-72). */
    public static function pem(\OpenSSLAsymmetricKey $key, \OpenSSLCertificate $certificate): string
    {
        if (!openssl_x509_export($certificate, $cert) || !openssl_pkey_export($key, $private)) {
            throw new \RuntimeException('Brak PEM pieczęci.');
        }
        return $cert . $private;
    }

    /**
     * Samopodpisana pieczęć `VATPL-<NIP>` (RSA 2048, ważna 2 dni); `certificateNotAfter` dostaje jej notAfter.
     *
     * @return array{\OpenSSLAsymmetricKey,\OpenSSLCertificate}
     */
    public function seal(string $nip): array
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
}
