<?php

declare(strict_types=1);

namespace App\Xml;

use App\Fx\FxRate;

/**
 * Dane KOR (docs/modules/ksef.md, „Korekty KOR”): oryginał z numerem KSeF albo bez niego (`NrKSeFN`), opcjonalne
 * `PrzyczynaKorekty` i `TypKorekty`, kurs VAT faktury pierwotnej (art. 31b) przy walucie obcej.
 */
final readonly class Fa3Correction
{
    public function __construct(
        public string $originalNumber,
        public string $originalIssueDate,
        public ?string $originalKsefNumber,
        public ?string $reason = null,
        public ?int $type = null,
        public ?FxRate $originalVatRate = null,
    ) {}
}
