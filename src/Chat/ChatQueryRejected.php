<?php

declare(strict_types=1);

namespace App\Chat;

/** Parametry nazwanego zapytania odrzucone przed SQL; `reason` to stały kod, bez wartości parametru. */
final class ChatQueryRejected extends \InvalidArgumentException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
