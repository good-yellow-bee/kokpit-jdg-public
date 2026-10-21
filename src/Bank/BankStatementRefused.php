<?php

declare(strict_types=1);

namespace App\Bank;

/** Odmowa przyjęcia pliku wyciągu; problemy podają numer rekordu CSV i pole, nigdy treść operacji. */
final class BankStatementRefused extends \RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('Odmowa importu wyciągu: ' . implode('; ', $problems));
    }
}
