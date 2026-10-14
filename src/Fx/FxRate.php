<?php

declare(strict_types=1);

namespace App\Fx;

/** Niezmienny wiersz `fx_rate`; numer tabeli to dowód kursu. */
final readonly class FxRate
{
    public function __construct(public string $id, public string $currency, public string $tableNo, public string $effectiveDate, public string $mid) {}
}
