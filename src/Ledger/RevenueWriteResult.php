<?php

declare(strict_types=1);

namespace App\Ledger;

/** Wynik zapisu dokumentu: nowe wiersze albo wiersze, które już go ewidencjonują (klucz deduplikacji). */
final readonly class RevenueWriteResult
{
    /**
     * @param list<string> $written
     * @param list<string> $existing
     */
    public function __construct(public array $written, public array $existing, public ?string $existingRevenueDate = null) {}

    public function duplicate(): bool
    {
        return $this->written === [] && $this->existing !== [];
    }
}
