<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Connector\Jpk\JpkEnvelope;
use App\Connector\Jpk\JpkException;
use App\Connector\Jpk\JpkSealedFile;
use App\Security\SecretStore;
use App\Tests\Support\EvidenceArtefacts;
use App\Tests\Support\JpkSendTestTools;
use App\Tests\Support\JpkTestTools;
use Symfony\Component\Clock\MockClock;

/**
 * Kryterium `jpk-envelope` (F5.1): ZIP z DEFLATE, części do 62 914 560 bajtów, AES-256-CBC z PKCS#7, MD5 części
 * w Base64 i klucz AES opakowany RSA-2048. Odszyfrowanie fikcyjnym kluczem prywatnym (para z `openssl_pkey_new`,
 * tylko w pamięci) i rozpakowanie daje bajty równe `xml_sent`.
 */
final class JpkEnvelopeTest extends DatabaseTestCase
{
    public function testJpkEwpFromFictionalDatasetRoundTrips(): void
    {
        $clock = new MockClock(JpkSendTestTools::NOW);
        JpkTestTools::seed($this->db(), $clock);
        $secrets = self::getContainer()->get(SecretStore::class);
        self::assertInstanceOf(SecretStore::class, $secrets);
        $xml = JpkTestTools::builder($this->db(), $clock, $secrets)->build(JpkSendTestTools::YEAR)->xml;

        $sealed = (new JpkEnvelope($clock, JpkSendTestTools::PART_MAX_BYTES))->seal($xml, 'JPK_EWP_20260101_fictional.xml', JpkSendTestTools::keys()['certificate']);
        self::assertCount(1, $sealed->parts);
        $this->assertRoundTrip($sealed, $xml, 'JPK_EWP_20260101_fictional.xml');
        self::assertSame('JPK_EWP_20260101_fictional.zip.001.aes', $sealed->parts[0]->fileName);
        // Klucz AES opakowany RSA-2048 z PKCS#1: 256 bajtów.
        self::assertSame(256, strlen((string) base64_decode($sealed->encryptedKeyBase64, true)));
        // Każde zapieczętowanie losuje nowy klucz i IV.
        $again = (new JpkEnvelope($clock, JpkSendTestTools::PART_MAX_BYTES))->seal($xml, 'JPK_EWP_20260101_fictional.xml', JpkSendTestTools::keys()['certificate']);
        self::assertNotSame($sealed->ivBase64(), $again->ivBase64());
        self::assertNotSame($sealed->parts[0]->bytes, $again->parts[0]->bytes);

        EvidenceArtefacts::write('cmd/jpk-envelope.txt', sprintf(
            "xml_sent sha256:      %s\nHashValue (Base64):   %s\nliczba części:        %d\nMD5 części (Base64):  %s\nZIP (bajty):          %d\n",
            hash('sha256', $xml),
            $sealed->documentSha256Base64,
            count($sealed->parts),
            implode(', ', array_map(static fn($part): string => $part->md5Base64, $sealed->parts)),
            $sealed->zipLength,
        ));
    }

    public function testZipAboveLimitIsSplitIntoPartsWithinLimit(): void
    {
        $clock = new MockClock(JpkSendTestTools::NOW);
        // Losowe bajty nie kompresują się, więc ZIP przekracza 62 914 560 bajtów.
        $document = random_bytes(JpkSendTestTools::PART_MAX_BYTES + 1024 * 1024);
        $sealed = (new JpkEnvelope($clock, JpkSendTestTools::PART_MAX_BYTES))->seal($document, 'JPK_EWP_20260101_large.xml', JpkSendTestTools::keys()['certificate']);
        self::assertGreaterThan(JpkSendTestTools::PART_MAX_BYTES, $sealed->zipLength);
        self::assertCount(2, $sealed->parts);
        self::assertSame(['JPK_EWP_20260101_large.zip.001.aes', 'JPK_EWP_20260101_large.zip.002.aes'], $sealed->partNames());
        $this->assertRoundTrip($sealed, $document, 'JPK_EWP_20260101_large.xml');
    }

    public function testShortRsaKeyIsRefused(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'Fikcyjny krotki klucz (fictional)'], $key);
        self::assertInstanceOf(\OpenSSLCertificateSigningRequest::class, $csr);
        $certificate = openssl_csr_sign($csr, null, $key, 1);
        self::assertNotFalse($certificate);
        self::assertTrue(openssl_x509_export($certificate, $pem));
        self::assertIsString($pem);
        try {
            (new JpkEnvelope(new MockClock(JpkSendTestTools::NOW), JpkSendTestTools::PART_MAX_BYTES))->seal('<fictional/>', 'JPK_fictional.xml', $pem);
            self::fail('Klucz RSA krótszy niż 2048 bitów przeszedł.');
        } catch (JpkException $exception) {
            self::assertSame('mf_key_invalid', $exception->resultCode);
        }
    }

    private function assertRoundTrip(JpkSealedFile $sealed, string $document, string $name): void
    {
        $sizes = 0;
        foreach ($sealed->parts as $index => $part) {
            self::assertSame($index + 1, $part->ordinal);
            self::assertLessThanOrEqual(JpkSendTestTools::PART_MAX_BYTES, $part->length());
            self::assertSame(0, $part->length() % 16);
            self::assertSame(24, strlen($part->md5Base64));
            self::assertSame(base64_encode(md5($part->bytes, true)), $part->md5Base64);
            $sizes += $part->length();
        }
        self::assertGreaterThan($sealed->zipLength, $sizes);
        [$entry, $bytes] = JpkSendTestTools::unzip(JpkSendTestTools::decrypt(array_map(static fn($part): string => $part->bytes, $sealed->parts), $sealed->encryptedKeyBase64, $sealed->ivBase64()));
        self::assertSame($name, $entry);
        self::assertTrue($bytes === $document, 'Odszyfrowany i rozpakowany plik różni się od xml_sent.');
        self::assertSame(base64_encode(hash('sha256', $document, true)), $sealed->documentSha256Base64);
        self::assertSame(strlen($document), $sealed->documentLength);
    }
}
