<?php

declare(strict_types=1);

namespace App\Calendar;

/** Rok i miesiąc z daty prawnej ISO `RRRR-MM-DD` (D-105). */
final class IsoDate
{
    public static function year(string $date): int
    {
        // Rzutowanie bierze cyfry przed pierwszym `-`.
        return (int) $date;
    }

    public static function month(string $date): int
    {
        return (int) explode('-', $date)[1];
    }
}
