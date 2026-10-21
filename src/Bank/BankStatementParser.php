<?php

declare(strict_types=1);

namespace App\Bank;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Czytnik wyciągu jednego banku i formatu (D-108); kolejne banki i MT940 to kolejne implementacje. */
#[AutoconfigureTag(self::TAG)]
interface BankStatementParser
{
    public const TAG = 'app.bank_statement_parser';

    /** Wartość `bank_statement.format`. */
    public function format(): string;

    public function supports(string $bytes): bool;

    /** @throws BankStatementRefused błąd w dowolnym wierszu odrzuca cały plik */
    public function parse(string $bytes): ParsedStatement;
}
