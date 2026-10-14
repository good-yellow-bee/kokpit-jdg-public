<?php

declare(strict_types=1);

namespace App\Messenger\Message;

/** Zadania `approval.expire`, `approval.poll_status`, `approval.reconcile` na `reads`; niesie tylko identyfikator zadania. */
final readonly class ApprovalTaskMessage
{
    public function __construct(public string $id) {}
}
