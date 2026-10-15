<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\Secrets\AbstractVault;

/**
 * Sejf testowy w pamięci procesu dla przebiegów live (F3.5, D-70): token KSeF wytworzony na TE i losowy klucz
 * `SECRETBOX_KEY_current` żyją tylko w tym obiekcie. Nic nie trafia do plików, repo ani logów.
 */
final class MemoryVault extends AbstractVault
{
    /** @param array<string,string> $secrets */
    public function __construct(private array $secrets = [])
    {
        $this->secrets += ['SECRETBOX_KEY_current' => base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES))];
    }

    public function generateKeys(bool $override = false): bool
    {
        return false;
    }

    public function seal(string $name, string $value): void
    {
        $this->validateName($name);
        $this->secrets[$name] = $value;
    }

    public function reveal(string $name): ?string
    {
        return $this->secrets[$name] ?? null;
    }

    public function remove(string $name): bool
    {
        $known = isset($this->secrets[$name]);
        unset($this->secrets[$name]);
        return $known;
    }

    /** @return array<string,?string> */
    public function list(bool $reveal = false): array
    {
        return array_map(static fn(string $value): ?string => $reveal ? $value : null, $this->secrets);
    }
}
