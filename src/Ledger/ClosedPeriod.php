<?php

declare(strict_types=1);

namespace App\Ledger;

/** Zapis do zamkniętego miesiąca odrzucony; `proposed` to najwcześniejszy otwarty okres (docs/compliance-calendar.md, „Blokada okresu”). */
final class ClosedPeriod extends \DomainException
{
    public function __construct(public readonly string $period, public readonly string $proposed, string $what)
    {
        parent::__construct(sprintf('Okres %s jest zamknięty: %s. Najwcześniejszy otwarty okres to %s.', substr($period, 0, 7), $what, substr($proposed, 0, 7)));
    }
}
