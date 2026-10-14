<?php

declare(strict_types=1);

namespace App\Ledger;

/**
 * Stan faktury Subject1 w ewidencji: `written`, `duplicate`, `fields_only` (przed przełączeniem) albo powód braku wpisu:
 * `not_vat`, `revenue_date_manual`, `rate_missing`, `fx_rate_pending`.
 */
final readonly class Subject1LedgerResult
{
    public function __construct(public string $invoiceId, public string $outcome, public ?RevenueWriteResult $write = null) {}
}
