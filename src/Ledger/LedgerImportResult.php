<?php

declare(strict_types=1);

namespace App\Ledger;

final readonly class LedgerImportResult
{
    /** @param array<string,array<string,array{rows:int,sum:string}>> $sums miesiąc K_3 → stawka K_9 → liczba wierszy i suma K_8 */
    public function __construct(
        public int $year,
        public int $rowsInFile,
        public int $rowsAdded,
        public ?string $evidenceSha256,
        public int $rowsInLedger,
        public string $ledgerSha256,
        public array $sums,
    ) {}
}
