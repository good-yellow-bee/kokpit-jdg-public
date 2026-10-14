<?php

declare(strict_types=1);

namespace App\Connector\Ksef;

final class KsefException extends \RuntimeException
{
    public function __construct(public readonly KsefError $error)
    {
        parent::__construct('ksef_' . $error->value);
    }
}
