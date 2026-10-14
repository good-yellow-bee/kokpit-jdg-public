<?php

declare(strict_types=1);

namespace App\Messenger\Message;

/** Zadanie `notifications.dispatch`; niesie tylko identyfikator zadania. */
final readonly class NotificationDispatchMessage
{
    public function __construct(public string $id) {}
}
