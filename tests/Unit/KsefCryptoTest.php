<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Connector\Ksef\KsefCrypto;
use App\Connector\Ksef\KsefException;
use App\Tests\Support\KsefSendTestTools;
use PHPUnit\Framework\TestCase;

/**
 * Kryptografia KSeF (F3.5, D-70): RSA-OAEP z SHA-256 i MGF1-SHA-256 odszyfrowana kluczem prywatnym fikcyjnego
 * certyfikatu MF daje tekst jawny; losowy seed daje inny szyfrogram za każdym razem; AES-256-CBC z PKCS#7 wraca do
 * tych samych bajtów. Bez sieci, dane fikcyjne.
 */
final class KsefCryptoTest extends TestCase
{
    public function testOaepSha256RoundTripsWithPrivateKey(): void
    {
        [$json, $key] = KsefSendTestTools::fictionalMfCertificates();
        $pem = self::pem($json);
        $plain = 'fikcyjny-token|1792070118929';
        $first = KsefCrypto::rsaOaepSha256($plain, $pem);
        $second = KsefCrypto::rsaOaepSha256($plain, $pem);
        self::assertSame(256, strlen($first));
        self::assertNotSame($first, $second);
        self::assertSame($plain, self::decrypt($first, $key));
        self::assertSame($plain, self::decrypt($second, $key));
        $aesKey = random_bytes(32);
        self::assertSame($aesKey, self::decrypt(KsefCrypto::rsaOaepSha256($aesKey, $pem), $key));
    }

    public function testOaepRefusesTooLongMessage(): void
    {
        [$json] = KsefSendTestTools::fictionalMfCertificates();
        $this->expectException(KsefException::class);
        KsefCrypto::rsaOaepSha256(str_repeat('x', 191), self::pem($json));
    }

    public function testAesCbcPkcs7RoundTrips(): void
    {
        $key = random_bytes(32);
        $iv = random_bytes(16);
        $xml = '<?xml version="1.0" encoding="UTF-8"?><Faktura>fikcyjna</Faktura>';
        $encrypted = KsefCrypto::aes($xml, $key, $iv);
        self::assertSame(0, strlen($encrypted) % 16);
        self::assertGreaterThan(strlen($xml), strlen($encrypted));
        self::assertSame($xml, openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv));
    }

    /** Odwrócenie OAEP (RFC 8017, 7.1.2) po surowym RSA kluczem prywatnym. */
    public static function decrypt(string $cipher, \OpenSSLAsymmetricKey $key): string
    {
        self::assertTrue(openssl_private_decrypt($cipher, $em, $key, OPENSSL_NO_PADDING));
        self::assertIsString($em);
        $hLen = 32;
        $maskedSeed = substr($em, 1, $hLen);
        $maskedDb = substr($em, 1 + $hLen);
        $seed = $maskedSeed ^ self::mgf1($maskedDb, $hLen);
        $db = $maskedDb ^ self::mgf1($seed, strlen($maskedDb));
        self::assertSame("\0", $em[0]);
        self::assertSame(hash('sha256', '', true), substr($db, 0, $hLen));
        $rest = ltrim(substr($db, $hLen), "\0");
        self::assertSame("\x01", $rest[0]);
        return substr($rest, 1);
    }

    private static function pem(string $json): string
    {
        $certificates = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($certificates);
        self::assertIsArray($certificates[0]);
        self::assertIsString($certificates[0]['certificate']);
        return "-----BEGIN CERTIFICATE-----\n" . chunk_split($certificates[0]['certificate'], 64, "\n") . "-----END CERTIFICATE-----\n";
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
