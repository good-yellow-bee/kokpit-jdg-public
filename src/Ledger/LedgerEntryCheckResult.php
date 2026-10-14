<?php

declare(strict_types=1);

namespace App\Ledger;

/** Wynik `ledger.entry_check` dla miesiąca: braki, czy kontrola działała (po przełączeniu) i czy zamknęła ewidencję. */
final readonly class LedgerEntryCheckResult
{
    /** @param list<array{type:string,id:string,number:string,date:string}> $missing */
    public function __construct(public string $month, public array $missing, public bool $active, public bool $closed) {}
}
