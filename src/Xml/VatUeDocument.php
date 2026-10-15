<?php

declare(strict_types=1);

namespace App\Xml;

/**
 * Zbudowana informacja VAT-UE(5) albo korekta VAT-UEK(5) za miesiąc (D-66): bajty, wynik walidacji XSD
 * (`LIBXML_NONET`), pozycje Grupy 3 (usługi art. 28b) z niezaokrągloną sumą `net_pln` i P_Uc w pełnych złotych.
 * Przy VAT-UEK `before` to pozycje złożenia zastępowanego (BYŁO), a `positions` stan miesiąca po korekcie (JEST).
 *
 * @phpstan-type Position array{country:string,vat_number:string,net_pln:string,p_uc:int,invoices:int}
 */
final readonly class VatUeDocument
{
    /**
     * @param 'VAT_UE'|'VAT_UEK' $type
     * @param list<Position> $positions stan miesiąca: jedna pozycja na kraj i numer VAT UE nabywcy
     * @param list<Position> $before pozycje złożenia zastępowanego (tylko VAT-UEK)
     * @param list<string> $xsdErrors pusta lista oznacza zgodność z XSD
     */
    public function __construct(
        public string $type,
        public string $periodStart,
        public string $xml,
        public array $positions,
        public array $before,
        public string $netPlnSum,
        public int $pUcSum,
        public int $invoices,
        public int $excludedUnidentified,
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

    /** @return array<string,mixed> klucze `filing.totals` VAT-UE z docs/naming.md („Implementacja F5.5”) */
    public function totals(): array
    {
        return [
            'positions' => $this->positions,
            'net_pln_sum' => $this->netPlnSum,
            'p_uc_sum' => $this->pUcSum,
            'buyers' => count($this->positions),
            'invoices' => $this->invoices,
            'excluded_unidentified' => $this->excludedUnidentified,
            'xml_sha256' => $this->sha256(),
        ] + ($this->type === 'VAT_UEK' ? ['before' => $this->before] : []);
    }
}
