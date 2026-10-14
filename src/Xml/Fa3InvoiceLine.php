<?php

declare(strict_types=1);

namespace App\Xml;

/**
 * Pozycja faktury w DTO niezależnym od źródła. Kwoty, ilość i cena jako napisy dziesiętne (`brick/math`).
 * `vatCode` to P_12 (np. `23`, `np I`, `np II`); w KOR `stateBefore` oznacza wiersz sprzed korekty (StanPrzed).
 */
final readonly class Fa3InvoiceLine
{
    public function __construct(
        public int $lineNo,
        public string $description,
        public string $unit,
        public string $quantity,
        public string $unitNetPrice,
        public string $netAmount,
        public string $vatCode,
        public ?string $serviceDate = null,
        public ?string $pkwiu = null,
        public bool $stateBefore = false,
    ) {}
}
