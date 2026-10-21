<?php

declare(strict_types=1);

namespace App\Bank;

/** Podgląd wyciągu `pending`: nowe operacje z propozycjami, liczba duplikatów i operacji z datą po dziś. */
final readonly class BankPreview
{
    /**
     * @param list<BankLine> $lines
     * @param array<string,BankMatch> $proposals `line_hash` → propozycja
     */
    public function __construct(public array $lines, public array $proposals, public int $duplicates, public int $future) {}

    /** Odcisk zbioru nowych operacji; zatwierdzenie z innym odciskiem jest odrzucane. */
    public function fingerprint(): string
    {
        return hash('sha256', implode("\n", array_map(static fn(BankLine $line): string => $line->hash, $this->lines)));
    }
}
