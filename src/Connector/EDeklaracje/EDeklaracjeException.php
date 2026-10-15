<?php

declare(strict_types=1);

namespace App\Connector\EDeklaracje;

/** Odmowa bramki e-Deklaracji albo kontroli przed wysyłką; `resultCode` trafia do `approval.result_code` i `filing.status_code`. */
final class EDeklaracjeException extends \RuntimeException
{
    public function __construct(public readonly string $resultCode, string $detail = '')
    {
        parent::__construct('edeklaracje_' . $resultCode . ($detail === '' ? '' : ': ' . $detail));
    }

    public static function of(EDeklaracjeError $error): self
    {
        return new self($error->value);
    }
}
