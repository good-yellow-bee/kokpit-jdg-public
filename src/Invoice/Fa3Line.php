<?php

declare(strict_types=1);

namespace App\Invoice;

/** Pozycja `FaWiersz` z FA(3); kwoty jako tekst dziesiętny ze schematu. */
final readonly class Fa3Line
{
    public function __construct(
        public int $lineNo,
        public bool $stateBefore,
        public string $description,
        public string $unit,
        public string $quantity,
        public ?string $unitNetPrice,
        public string $netAmount,
        public string $vatCode,
        public ?string $vatAmount,
        public ?string $serviceDate,
        public ?string $gtu,
    ) {}
}
