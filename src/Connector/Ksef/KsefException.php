<?php

declare(strict_types=1);

namespace App\Connector\Ksef;

final class KsefException extends \RuntimeException
{
    /** @param ?string $detail kod błędu z odpowiedzi KSeF, np. `exceptionCode`, bez danych osobowych */
    public function __construct(public readonly KsefError $error, public readonly ?string $detail = null)
    {
        parent::__construct('ksef_' . $error->value);
    }
}
