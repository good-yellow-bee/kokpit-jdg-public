<?php

declare(strict_types=1);

namespace App\Messenger\Message;

/** Zadanie `latarnia.status` na `reads`; niesie tylko identyfikator zadania. */
final readonly class LatarniaStatusMessage
{
    public function __construct(public string $id) {}
}
