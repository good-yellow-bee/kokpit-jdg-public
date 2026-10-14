<?php

declare(strict_types=1);

namespace App\Messenger\Message;

/** Zadania `freshbooks.poll_invoices` i `freshbooks.poll_payments` na `reads`; niesie tylko identyfikator zadania. */
final readonly class FreshBooksPollMessage
{
    public function __construct(public string $id) {}
}
