<?php

declare(strict_types=1);

namespace App\Messenger\Message;

final readonly class FailureNotificationMessage
{
    public function __construct(public string $id) {}
}
