<?php

declare(strict_types=1);

namespace App\Connector\Jpk;

use Symfony\Component\Clock\ClockInterface;

/**
 * Certyfikat klucza publicznego MF do opakowania klucza AES, osobny dla celu `test` i `prod` (docs/modules/jpk.md,
 * „Środowiska”). Ścieżki per cel z konfiguracji `jpk.mf_certificates`, poza repo; odświeża je zadanie `mf_keys.refresh`.
 * Brak, wygasły albo niepoprawny certyfikat wstrzymuje wysyłkę jako warunek przejściowy (docs/approval-gate.md).
 */
final readonly class JpkMfCertificate
{
    /** @param array<string,string> $paths cel `JPK_TARGET` → plik PEM certyfikatu */
    public function __construct(private array $paths, private ClockInterface $clock) {}

    /** Powód wstrzymania wysyłki albo NULL, gdy certyfikat celu jest ważny. */
    public function problem(string $target): ?string
    {
        $pem = $this->read($target);
        if ($pem === null) {
            return 'mf_key_missing';
        }
        $certificate = openssl_x509_read($pem);
        $parsed = $certificate === false ? false : openssl_x509_parse($certificate);
        if ($certificate === false || $parsed === false || !is_int($parsed['validTo_time_t'] ?? null) || !is_int($parsed['validFrom_time_t'] ?? null)) {
            return 'mf_key_invalid';
        }
        $now = $this->clock->now()->getTimestamp();
        if ($parsed['validTo_time_t'] <= $now || $parsed['validFrom_time_t'] > $now) {
            return 'mf_key_expired';
        }
        return null;
    }

    /** PEM certyfikatu celu; wyjątek, gdy certyfikat nie nadaje się do wysyłki. */
    public function pem(string $target): string
    {
        $pem = $this->read($target);
        if ($pem === null || $this->problem($target) !== null) {
            throw JpkException::of(JpkError::MfKeyInvalid);
        }
        return $pem;
    }

    private function read(string $target): ?string
    {
        $path = $this->paths[$target] ?? null;
        if ($path === null || !is_file($path)) {
            return null;
        }
        $pem = file_get_contents($path);
        return is_string($pem) && $pem !== '' ? $pem : null;
    }
}
