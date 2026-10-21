<?php

declare(strict_types=1);

namespace App\Bank;

use Brick\Math\BigDecimal;

/** Operacja z wyciągu; `amount` ze znakiem (minus = obciążenie), `account` to NRB własnego rachunku. */
final readonly class BankLine
{
    public function __construct(
        public string $hash,
        public string $account,
        public string $bookedOn,
        public BigDecimal $amount,
        public string $currency,
        public ?string $operationType,
        public string $description,
        public ?string $counterpartyAccount,
        public BankLineKind $kind,
        public bool $splitPayment,
    ) {}
}
