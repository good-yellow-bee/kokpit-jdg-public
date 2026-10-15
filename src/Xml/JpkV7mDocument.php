<?php

declare(strict_types=1);

namespace App\Xml;

/**
 * Zbudowany JPK_V7M(3) za miesiąc: bajty, wynik walidacji XSD (`LIBXML_NONET`), sumy do `filing.totals`, pola
 * deklaracji w pełnych złotych i lista pozycji `DI` do `approval.summary` (D-65).
 */
final readonly class JpkV7mDocument
{
    /**
     * @param array<string,int|string> $totals klucze `filing.totals` z docs/naming.md („Implementacja F5.4”)
     * @param array<string,string> $declaration pola P_xx deklaracji w kolejności pliku, w pełnych złotych
     * @param list<array{register:string,lp:int,document:string,counterparty:string,reason:string}> $diItems pozycje `DI`
     * @param list<string> $xsdErrors pusta lista oznacza zgodność z XSD
     */
    public function __construct(
        public string $periodStart,
        public int $purpose,
        public string $xml,
        public array $totals,
        public array $declaration,
        public array $diItems,
        public string $p39Source,
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
