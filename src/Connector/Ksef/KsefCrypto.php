<?php

declare(strict_types=1);

namespace App\Connector\Ksef;

/**
 * Kryptografia KSeF API 2.0 (docs/modules/ksef.md, „Uwierzytelnienie”; [szyfrowanie faktur](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/sesja-interaktywna.md)):
 * RSA-OAEP z SHA-256 i MGF1-SHA-256 kluczem publicznym MF (token KSeF i klucz sesji) oraz AES-256-CBC z PKCS#7 dla
 * faktury. `openssl_public_encrypt` zna tylko OAEP z SHA-1, więc dopełnienie OAEP (RFC 8017, 7.1.1) powstaje tutaj,
 * a szyfrowanie RSA idzie bez dopełnienia.
 */
final class KsefCrypto
{
    public const AES = 'aes-256-cbc';

    /** RSA-OAEP-SHA256 z pustą etykietą; `$certificatePem` to certyfikat MF z `/security/public-key-certificates`. */
    public static function rsaOaepSha256(string $plain, string $certificatePem): string
    {
        $key = openssl_pkey_get_public($certificatePem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($key === false || $details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || !is_int($details['bits'] ?? null)) {
            throw new KsefException(KsefError::RequestRefused, 'mf_key_invalid');
        }
        $k = intdiv($details['bits'] + 7, 8);
        $hLen = 32;
        if (strlen($plain) > $k - 2 * $hLen - 2) {
            throw new KsefException(KsefError::RequestRefused, 'oaep_message_too_long');
        }
        $db = hash('sha256', '', true) . str_repeat("\0", $k - strlen($plain) - 2 * $hLen - 2) . "\x01" . $plain;
        $seed = random_bytes($hLen);
        $maskedDb = $db ^ self::mgf1($seed, $k - $hLen - 1);
        $maskedSeed = $seed ^ self::mgf1($maskedDb, $hLen);
        if (!openssl_public_encrypt("\0" . $maskedSeed . $maskedDb, $encrypted, $key, OPENSSL_NO_PADDING) || !is_string($encrypted)) {
            throw new KsefException(KsefError::RequestRefused, 'rsa_encrypt_failed');
        }
        return $encrypted;
    }

    /** AES-256-CBC z PKCS#7 (domyślne dopełnienie OpenSSL). */
    public static function aes(string $plain, string $key, string $iv): string
    {
        $encrypted = openssl_encrypt($plain, self::AES, $key, OPENSSL_RAW_DATA, $iv);
        return $encrypted === false ? throw new KsefException(KsefError::RequestRefused, 'aes_encrypt_failed') : $encrypted;
    }

    private static function mgf1(string $seed, int $length): string
    {
        $mask = '';
        for ($counter = 0; strlen($mask) < $length; ++$counter) {
            $mask .= hash('sha256', $seed . pack('N', $counter), true);
        }
        return substr($mask, 0, $length);
    }
}
