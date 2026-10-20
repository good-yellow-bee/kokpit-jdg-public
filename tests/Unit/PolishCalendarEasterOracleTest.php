<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Calendar\PolishCalendar;
use App\Tests\Support\CalendarGoldenCases;
use PHPUnit\Framework\TestCase;

/** Wielkanoc z `PolishCalendar::easter` wobec niezależnego wzoru Gaussa w wersji Lichtenberga, wszystkie lata gregoriańskie z czterema cyframi (1583–9999). */
final class PolishCalendarEasterOracleTest extends TestCase
{
    public function testOracleMatchesGoldenDates(): void
    {
        foreach (CalendarGoldenCases::easter() as $year => $date) {
            self::assertSame($date, self::lichtenberg((int) $year));
        }
        // Lata, w których poprawka Meeusa (dzielenie przez 451) przesuwa datę: 1954, 1981, 2049, 2076.
        self::assertSame(['1954-04-18', '1981-04-19', '2049-04-18', '2076-04-19'], array_map(self::lichtenberg(...), [1954, 1981, 2049, 2076]));
    }

    public function testEasterMatchesOracleForEveryGregorianYear(): void
    {
        for ($year = 1583; $year <= 9999; $year++) {
            self::assertSame(self::lichtenberg($year), PolishCalendar::easter($year), (string) $year);
        }
    }

    public function testHolidaysFollowEasterOfTheYear(): void
    {
        $calendar = new PolishCalendar();
        // 2049: Wielkanoc 18.04, poniedziałek 19.04, Zielone Świątki 6.06, Boże Ciało 17.06.
        self::assertSame(['2049-04-18', '2049-04-19', '2049-06-06', '2049-06-17'], array_values(array_diff($calendar->holidays(2049), ['2049-01-01', '2049-01-06', '2049-05-01', '2049-05-03', '2049-08-15', '2049-11-01', '2049-11-11', '2049-12-24', '2049-12-25', '2049-12-26'])));
    }

    private static function lichtenberg(int $year): string
    {
        $k = intdiv($year, 100);
        $m = 15 + intdiv(3 * $k + 3, 4) - intdiv(8 * $k + 13, 25);
        $s = 2 - intdiv(3 * $k + 3, 4);
        $a = $year % 19;
        $d = (19 * $a + $m) % 30;
        $r = intdiv($d + intdiv($a, 11), 29);
        $og = 21 + $d - $r;
        $sz = 7 - ($year + intdiv($year, 4) + $s) % 7;
        $oe = 7 - ($og - $sz) % 7;
        $march = $og + $oe;
        return $march > 31 ? sprintf('%04d-04-%02d', $year, $march - 31) : sprintf('%04d-03-%02d', $year, $march);
    }
}
