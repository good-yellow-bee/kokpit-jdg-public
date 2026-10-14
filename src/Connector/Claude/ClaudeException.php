<?php

declare(strict_types=1);

namespace App\Connector\Claude;

/** Błąd wywołania Messages API bez treści żądania i odpowiedzi; `blockedUntil` przy limicie `claude.messages`. */
final class ClaudeException extends \RuntimeException
{
    public function __construct(public readonly ClaudeError $error, public readonly ?\DateTimeImmutable $blockedUntil = null)
    {
        parent::__construct($error->value);
    }
}
