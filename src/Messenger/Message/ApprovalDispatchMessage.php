<?php

declare(strict_types=1);

namespace App\Messenger\Message;

/** Wysyłka jednego zatwierdzenia na transporcie `official_writes`; niesie tylko `approval_id` w polu `id` (RedactingTransportSerializer). */
final readonly class ApprovalDispatchMessage
{
    public function __construct(public string $id) {}
}
