<?php

declare(strict_types=1);

namespace App\Cost;

/**
 * Koszt spoza KSeF wpisany ręcznie (docs/data-model.md, „Faktura kosztowa”). Kwoty w walucie dokumentu, jako tekst;
 * `taxPointDate` to obowiązek podatkowy z dokumentu albo z importu usług.
 */
final readonly class ManualCost
{
    /**
     * @param array{name:string,country:string,nip?:?string,eu_vat_prefix?:?string,eu_vat_number?:?string,foreign_tax_id?:?string,is_natural_person?:bool}|null $supplier nowy dostawca, gdy brak `counterpartyId`
     */
    public function __construct(
        public string $source,
        public ?string $counterpartyId,
        public ?array $supplier,
        public string $invoiceNumber,
        public string $issueDate,
        public ?string $taxPointDate,
        public string $receivedOn,
        public string $currency,
        public string $netTotal,
        public string $vatTotal,
        public string $grossTotal,
        public ?string $importServices = null,
        public bool $vatExempt = false,
        public bool $deductible = true,
        public bool $fixedAsset = false,
        public ?string $jpkDocType = null,
        public string $kind = 'VAT',
    ) {}
}
