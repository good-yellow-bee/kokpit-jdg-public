<?php

declare(strict_types=1);

namespace App\Messenger\Message;

/** Zadanie cykliczne rejestrów: `counterparty.refresh_due`. */
final readonly class RegistryTaskMessage
{
    public function __construct(public string $id) {}
}
