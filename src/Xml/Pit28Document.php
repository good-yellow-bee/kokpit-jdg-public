<?php

declare(strict_types=1);

namespace App\Xml;

/**
 * Zbudowane zeznanie PIT-28 za rok przychodu (D-96): bajty bez `DaneAutoryzujace`, wariant formularza, wynik walidacji
 * XSD (`LIBXML_NONET`) i kwoty z ewidencji, `zus_payment` i wpłat zaliczek, z których powstało.
 */
final readonly class Pit28Document
{
    /**
     * @param array<string,mixed> $totals klucze `filing.totals` PIT-28 z docs/naming.md („PIT-28 przez e-Deklaracje”)
     * @param list<string> $xsdErrors pusta lista oznacza zgodność z XSD
     */
    public function __construct(
        public int $year,
        public string $variant,
        public string $schema,
        public string $xml,
        public array $totals,
        public array $xsdErrors,
    ) {}

    public function sha256(): string
    {
        return hash('sha256', $this->xml);
    }

    public function valid(): bool
    {
        return $this->xsdErrors === [];
    }
}
