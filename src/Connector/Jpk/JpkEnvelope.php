<?php

declare(strict_types=1);

namespace App\Connector\Jpk;

use Symfony\Component\Clock\ClockInterface;

/**
 * Koperta pliku JPK (docs/modules/jpk.md, „Przepływ wysyłki”, kroki 4–7): ZIP z algorytmem DEFLATE i jednym
 * dokumentem, podział binarny na części do `jpk.part_max_bytes` (przed i po szyfrowaniu), losowy klucz AES-256 i IV, każda część szyfrowana
 * AES-256-CBC z PKCS#7, MD5 zaszyfrowanej części w Base64 i klucz AES opakowany RSA (ECB, PKCS#1) certyfikatem MF celu.
 * Bajty dokumentu przychodzą z `xml_sent` i nie są serializowane ponownie; zaszyfrowanych części kokpit nie zapisuje.
 */
final readonly class JpkEnvelope
{
    public const CIPHER = 'aes-256-cbc';
    /** Długość klucza RSA certyfikatu MF według specyfikacji 5.6.0. */
    public const RSA_BITS = 2048;

    /** Blok AES; dopełnienie PKCS#7 wydłuża część o 1–16 bajtów. */
    private const BLOCK = 16;

    public function __construct(private ClockInterface $clock, private int $partMaxBytes)
    {
        if ($partMaxBytes < 2 * self::BLOCK) {
            throw new \InvalidArgumentException('jpk.part_max_bytes musi mieć co najmniej 32 bajty.');
        }
    }

    /**
     * @param string $fileName nazwa dokumentu w ZIP i podstawa nazw części (`<nazwa>.zip.001.aes`)
     * @param string $certificate certyfikat klucza publicznego MF celu w PEM
     */
    public function seal(string $document, string $fileName, string $certificate): JpkSealedFile
    {
        $public = openssl_pkey_get_public($certificate);
        $details = $public === false ? false : openssl_pkey_get_details($public);
        if ($public === false || $details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || ($details['bits'] ?? 0) < self::RSA_BITS) {
            throw JpkException::of(JpkError::MfKeyInvalid);
        }
        $key = random_bytes(32);
        $iv = random_bytes(16);
        if (!openssl_public_encrypt($key, $wrapped, $public, OPENSSL_PKCS1_PADDING) || !is_string($wrapped)) {
            throw JpkException::of(JpkError::MfKeyInvalid);
        }
        $zip = $this->zip($document, $fileName);
        $parts = [];
        $base = preg_replace('/\.xml$/i', '', $fileName) ?? $fileName;
        // Kawałek ZIP o pełnych blokach i o jeden blok krótszy od limitu: po dopełnieniu PKCS#7 także zaszyfrowana
        // część mieści się w `jpk.part_max_bytes`.
        $chunkBytes = intdiv($this->partMaxBytes, self::BLOCK) * self::BLOCK - self::BLOCK;
        if ($chunkBytes < self::BLOCK) {
            throw new \LogicException('jpk.part_max_bytes poniżej dwóch bloków AES.');
        }
        foreach (str_split($zip, $chunkBytes) as $index => $chunk) {
            $encrypted = openssl_encrypt($chunk, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
            if ($encrypted === false) {
                throw JpkException::of(JpkError::MetadataInvalid);
            }
            $ordinal = $index + 1;
            $parts[] = new JpkPart($ordinal, sprintf('%s.zip.%03d.aes', $base, $ordinal), $encrypted, base64_encode(md5($encrypted, true)));
        }
        return new JpkSealedFile($key, $iv, base64_encode($wrapped), $parts, base64_encode(hash('sha256', $document, true)), strlen($document), strlen($zip));
    }

    /**
     * Archiwum ZIP z jednym wpisem DEFLATE, budowane w pamięci (bez pliku tymczasowego z treścią JPK). Czas wpisu
     * w formacie DOS z `ClockInterface` w Europe/Warsaw; nazwa w UTF-8 (bit 11).
     */
    private function zip(string $document, string $name): string
    {
        $compressed = gzdeflate($document, 9);
        if ($compressed === false || strlen($document) > 0xFFFFFFFF || strlen($compressed) > 0xFFFFFFFF) {
            throw JpkException::of(JpkError::MetadataInvalid);
        }
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Warsaw'));
        $time = ((int) $now->format('G') << 11) | ((int) $now->format('i') << 5) | ((int) $now->format('s') >> 1);
        $date = (((int) $now->format('Y') - 1980) << 9) | ((int) $now->format('n') << 5) | (int) $now->format('j');
        $crc = crc32($document);
        $common = pack('vvvvvVVVv', 20, 0x0800, 8, $time, $date, $crc, strlen($compressed), strlen($document), strlen($name));
        $local = pack('V', 0x04034B50) . $common . pack('v', 0) . $name;
        $central = pack('Vv', 0x02014B50, 20) . $common . pack('vvvvVV', 0, 0, 0, 0, 0, 0) . $name;
        $offset = strlen($local) + strlen($compressed);
        $end = pack('VvvvvVVv', 0x06054B50, 0, 0, 1, 1, strlen($central), $offset, 0);
        return $local . $compressed . $central . $end;
    }
}
