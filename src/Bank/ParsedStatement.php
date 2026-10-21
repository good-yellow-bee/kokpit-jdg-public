<?php

declare(strict_types=1);

namespace App\Bank;

final readonly class ParsedStatement
{
    /** @param list<BankLine> $lines */
    public function __construct(public string $periodFrom, public string $periodTo, public array $lines) {}
}
