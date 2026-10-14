<?php

declare(strict_types=1);

namespace App\Calendar;

/**
 * Dni wolne w Polsce i przesunięcia terminów (docs/compliance-calendar.md, „`PolishCalendar`”).
 * Daty to tekst RRRR-MM-DD (data prawna w Europe/Warsaw); arytmetyka dni w UTC, bez zmian czasu.
 */
final class PolishCalendar
{
    /** OP art. 12 § 5: sobota, niedziela i święto przesuwają termin na następny dzień roboczy. */
    public const OP12 = 'op12';
    /** Ustawa o systemie ubezpieczeń społecznych, art. 31: te same zasady co OP. */
    public const ZUS = 'zus';
    public const NONE = 'none';
    public const POLICIES = [self::OP12, self::ZUS, self::NONE];
    /** Dni stałe; 24 grudnia od 2025 r. (Dz.U. 2025 poz. 296). */
    private const FIXED = ['01-01','01-06','05-01','05-03','08-15','11-01','11-11','12-25','12-26'];
    /** @var array<int,array<string,true>> */
    private array $cache = [];
    public static function easter(int $year): string
    {
        // Algorytm anonimowy (Meeus/Jones/Butcher) dla kalendarza gregoriańskiego.
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
    /** @return list<string> święta roku, bez niedziel */
    public function holidays(int $year): array
    {
        $days = array_keys($this->holidaySet($year));
        sort($days);
        return $days;
    }
    public function isHoliday(string $date): bool
    {
        return isset($this->holidaySet((int) self::date($date)->format('Y'))[$date]);
    }
    public function isWorkingDay(string $date): bool
    {
        return (int) self::date($date)->format('N') < 6 && !$this->isHoliday($date);
    }
    /** Ostatni dzień roboczy przed datą (bez samej daty). */
    public function previousWorkingDay(string $date): string
    {
        $day = self::date($date);
        do {
            $day = $day->modify('-1 day');
        } while (!$this->isWorkingDay($day->format('Y-m-d')));
        return $day->format('Y-m-d');
    }
    /** Pierwszy dzień roboczy nie wcześniejszy niż data. */
    public function workingDayOnOrAfter(string $date): string
    {
        $day = self::date($date);
        while (!$this->isWorkingDay($day->format('Y-m-d'))) {
            $day = $day->modify('+1 day');
        }
        return $day->format('Y-m-d');
    }
    /** Termin po przesunięciu według polityki; `none` zwraca datę nominalną. */
    public function shift(string $nominal, string $policy): string
    {
        return match ($policy) {
            self::OP12, self::ZUS => $this->workingDayOnOrAfter($nominal),
            self::NONE => self::date($nominal)->format('Y-m-d'),
            default => throw new \InvalidArgumentException(sprintf('Nieznana polityka przesunięcia %s.', $policy)),
        };
    }
    /** @return array<string,true> */
    private function holidaySet(int $year): array
    {
        if (isset($this->cache[$year])) {
            return $this->cache[$year];
        }
        $set = [];
        foreach (self::FIXED as $monthDay) {
            $set[$year . '-' . $monthDay] = true;
        }
        if ($year >= 2025) {
            $set[$year . '-12-24'] = true;
        }
        $easter = self::date(self::easter($year));
        foreach ([0, 1, 49, 60] as $offset) {
            $set[$easter->modify('+' . $offset . ' days')->format('Y-m-d')] = true;
        }
        return $this->cache[$year] = $set;
    }
    private static function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(sprintf('Data %s nie ma formatu RRRR-MM-DD.', $value));
        }
        return $date;
    }
}
