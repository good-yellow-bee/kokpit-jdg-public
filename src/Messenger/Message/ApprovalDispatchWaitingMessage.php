<?php

declare(strict_types=1);

namespace App\Messenger\Message;

/** Zadanie `approval.dispatch_waiting` na `official_writes`; niesie tylko identyfikator zadania. */
final readonly class ApprovalDispatchWaitingMessage
{
    public function __construct(public string $id) {}
}
