<?php

declare(strict_types=1);

namespace App\Connector\Jpk;

/**
 * Koperta jednego dokumentu JPK w pamięci workera (docs/modules/jpk.md, „Przepływ wysyłki”, kroki 4–7): zaszyfrowane
 * części, IV, klucz AES opakowany certyfikatem MF i skrót dokumentu do metadanych. Klucz AES nie wychodzi poza obiekt;
 * `encrypt` szyfruje nim `AuthData` (ten sam klucz co części pliku).
 */
final readonly class JpkSealedFile
{
    /** @param list<JpkPart> $parts */
    public function __construct(
        private string $aesKey,
        private string $iv,
        public string $encryptedKeyBase64,
        public array $parts,
        public string $documentSha256Base64,
        public int $documentLength,
        public int $zipLength,
    ) {}

    public function ivBase64(): string
    {
        return base64_encode($this->iv);
    }

    /** AES-256-CBC z PKCS#7 kluczem i IV pliku; surowy szyfrogram. */
    public function encrypt(string $plain): string
    {
        $cipher = openssl_encrypt($plain, JpkEnvelope::CIPHER, $this->aesKey, OPENSSL_RAW_DATA, $this->iv);
        return $cipher === false ? throw JpkException::of(JpkError::MetadataInvalid) : $cipher;
    }

    /** @return list<string> */
    public function partNames(): array
    {
        return array_map(static fn(JpkPart $part): string => $part->fileName, $this->parts);
    }
}
