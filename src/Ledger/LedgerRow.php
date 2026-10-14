<?php

declare(strict_types=1);

namespace App\Ledger;

use Brick\Math\BigDecimal;

/** Wiersz ewidencji K_1–K_10 z pliku importu; K_2 null, gdy plik go nie podaje. */
final readonly class LedgerRow
{
    public function __construct(
        public int $lp,
        public ?string $entryDate,
        public string $revenueDate,
        public string $documentNumber,
        public ?string $ksefNumber,
        public ?string $counterpartyCountry,
        public ?string $counterpartyTaxId,
        public BigDecimal $amountPln,
        public BigDecimal $ratePct,
        public ?string $remarks,
    ) {}
    /** @return array<string,?string> K_1–K_10 jako tekst kanoniczny; porównanie z bazą i zrzut */
    public function canonical(): array
    {
        return ['K_1' => (string) $this->lp,'K_2' => $this->entryDate,'K_3' => $this->revenueDate,'K_4' => $this->documentNumber,'K_5' => $this->ksefNumber,'K_6' => $this->counterpartyCountry,'K_7' => $this->counterpartyTaxId,'K_8' => (string) $this->amountPln->toScale(2),'K_9' => (string) $this->ratePct->toScale(2),'K_10' => $this->remarks];
    }
}
