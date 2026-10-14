<?php

declare(strict_types=1);

namespace App\Http;

final class ConnectorException extends \RuntimeException
{
    public function __construct(public readonly ConnectorError $error, ?\Throwable $previous = null)
    {
        parent::__construct($error->value, 0, $previous);
    }
}
