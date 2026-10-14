<?php

declare(strict_types=1);

namespace App\Messenger\Message;

/** Zadanie `credentials.check_expiry`; niesie tylko identyfikator zadania. */
final readonly class CredentialExpiryMessage
{
    public function __construct(public string $id) {}
}
