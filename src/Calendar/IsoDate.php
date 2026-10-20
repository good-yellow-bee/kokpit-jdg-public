<?php

declare(strict_types=1);

namespace App\Calendar;

/** Rok i miesiąc z daty prawnej ISO `RRRR-MM-DD` (D-105); inny format to błąd, nie zgadywanie. */
final class IsoDate
{
    public static function year(string $date): int
    {
        return self::parts($date)[0];
    }

    public static function month(string $date): int
    {
        return self::parts($date)[1];
    }

    /** @return array{int,int} */
    private static function parts(string $date): array
    {
        if (!preg_match('/^(\d{4})-(\d{2})-\d{2}$/D', $date, $match)) {
            throw new \InvalidArgumentException('Data poza formatem RRRR-MM-DD.');
        }
        return [(int) $match[1], (int) $match[2]];
    }
}
