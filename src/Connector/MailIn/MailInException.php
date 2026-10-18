<?php

declare(strict_types=1);

namespace App\Connector\MailIn;

final class MailInException extends \RuntimeException
{
    public function __construct(public readonly MailInError $error, ?\Throwable $previous = null)
    {
        parent::__construct($error->value, 0, $previous);
    }
}
