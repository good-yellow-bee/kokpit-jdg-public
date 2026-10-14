<?php

declare(strict_types=1);

namespace App\Http;

final class ConnectorException extends \RuntimeException
{
    public function __construct(public readonly ConnectorError $error)
    {
        parent::__construct($error->value);
    }
}
