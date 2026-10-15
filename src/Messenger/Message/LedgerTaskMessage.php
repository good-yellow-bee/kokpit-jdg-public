<?php

declare(strict_types=1);

namespace App\Messenger\Message;

/** Zadanie `ledger.entry_check` albo `jpk.replace_di`; niesie tylko identyfikator zadania. */
final readonly class LedgerTaskMessage
{
    public function __construct(public string $id) {}
}
