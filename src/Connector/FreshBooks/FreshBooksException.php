<?php

declare(strict_types=1);

namespace App\Connector\FreshBooks;

final class FreshBooksException extends \RuntimeException
{
    public function __construct(public readonly FreshBooksError $error)
    {
        parent::__construct($error->value);
    }
}
