<?php

declare(strict_types=1);

namespace App\Messenger\Message;

/** Zadanie `obligations.recompute`; niesie tylko identyfikator zadania. */
final readonly class ObligationRecomputeMessage
{
    public function __construct(public string $id) {}
}
