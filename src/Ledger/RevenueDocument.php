<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Fx\FxRate;

/**
 * Dokument przychodu niezależny od źródła (D-25): FreshBooks, KSeF Subject1 i wpis ręczny dają ten kształt.
 * `netByRate` to kwota netto w walucie dokumentu per stawka ryczałtu (napisy dziesiętne).
 */
final readonly class RevenueDocument
{
    /** @param array<string,string> $netByRate stawka (np. "12.00") → kwota netto w walucie */
    public function __construct(
        public string $source,
        public ?string $invoiceId,
        public string $documentNumber,
        public ?string $ksefNumber,
        public ?string $counterpartyCountry,
        public ?string $counterpartyTaxId,
        public string $revenueDate,
        public string $currency,
        public ?FxRate $fxRate,
        public array $netByRate,
        public ?string $remarks = null,
    ) {
        if (!in_array($source, ['freshbooks','ksef_subject1','manual'], true)) {
            throw new \InvalidArgumentException(sprintf('Źródło %s nie tworzy wpisów z dokumentu.', $source));
        }
        if ($netByRate === []) {
            throw new \InvalidArgumentException('Dokument przychodu bez kwot.');
        }
        if (($currency === 'PLN') !== ($fxRate === null)) {
            throw new \InvalidArgumentException('Kurs PIT jest wymagany dokładnie przy walucie obcej.');
        }
    }
}
