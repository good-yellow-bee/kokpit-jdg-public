<?php

declare(strict_types=1);

namespace App\Bank;

/** Propozycja dopasowania wiersza wyciągu; `token` wiąże zaznaczenie w podglądzie z tym, co zatwierdzasz. */
final readonly class BankMatch
{
    public function __construct(public BankMatchRule $rule, public string $label, public ?string $invoiceId = null, public ?string $paymentId = null, public ?string $obligationId = null) {}

    public function token(string $lineHash): string
    {
        return implode('|', [$lineHash, $this->rule->value, $this->invoiceId ?? '', $this->paymentId ?? '', $this->obligationId ?? '']);
    }

    /** @return list<string> cele propozycji; każdy może mieć najwyżej jeden wiersz */
    public function targets(): array
    {
        return array_keys(array_filter(['invoice:' . $this->invoiceId => $this->invoiceId, 'payment:' . $this->paymentId => $this->paymentId, 'obligation:' . $this->obligationId => $this->obligationId], static fn(?string $id): bool => $id !== null));
    }
}
