<?php

declare(strict_types=1);

namespace App\Xml;

/** Zbudowany plik JPK z kontrolą pliku i wynikiem walidacji XSD (`LIBXML_NONET`). */
final readonly class JpkDocument
{
    /**
     * @param list<int> $lps K_1 wierszy w kolejności pliku
     * @param array<string,string> $sumsByRate suma K_8 per kod K_9
     * @param list<string> $xsdErrors pusta lista oznacza zgodność z XSD
     */
    public function __construct(
        public string $type,
        public string $schemaVersion,
        public int $year,
        public ?string $until,
        public int $purpose,
        public string $xml,
        public int $rows,
        public string $sum,
        public array $lps,
        public array $sumsByRate,
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
    /** K_1 ciągłe od 1, bez luk; obowiązuje tylko plik całego roku. */
    public function continuous(): bool
    {
        return $this->lps === ($this->lps === [] ? [] : range(1, count($this->lps)));
    }
}
