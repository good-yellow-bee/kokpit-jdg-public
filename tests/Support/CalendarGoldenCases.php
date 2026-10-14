<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Złote przypadki kalendarza z „Czasu, dat i kursów” w docs/testing.md: jeden przypadek na wartość z tabel.
 * Wiersze przypomnień 8:00 i wygaśnięcia zatwierdzenia mają przypadki w testach powiadomień i bramki.
 */
final class CalendarGoldenCases
{
    /** @return array<string,array{string,string,string}> nazwa przypadku → [metoda albo polityka, data wejściowa, wynik] */
    public static function dates(): array
    {
        $cases = [
            // PolishCalendar: święta ruchome, Wigilia, sobota przed Wielkanocą.
            ['op12', '2026-04-06', '2026-04-07'], ['op12', '2027-03-29', '2027-03-30'], ['op12', '2026-06-04', '2026-06-05'], ['op12', '2027-05-27', '2027-05-28'],
            ['op12', '2028-04-15', '2028-04-18'], ['op12', '2028-06-15', '2028-06-16'], ['op12', '2026-12-24', '2026-12-28'],
            ['previousWorkingDay', '2026-04-07', '2026-04-03'], ['previousWorkingDay', '2026-06-05', '2026-06-03'], ['previousWorkingDay', '2027-03-30', '2027-03-26'],
            ['previousWorkingDay', '2025-12-29', '2025-12-23'], ['previousWorkingDay', '2027-01-04', '2026-12-31'],
            // Terminy obowiązków: termin nominalny → due_date.
            ['zus', '2027-06-20', '2027-06-21'], ['zus', '2028-05-20', '2028-05-22'],
            ['op12', '2026-12-20', '2026-12-21'], ['op12', '2026-11-07', '2026-11-09'], ['op12', '2026-10-25', '2026-10-26'], ['op12', '2026-12-25', '2026-12-28'], ['op12', '2028-12-25', '2028-12-27'],
            ['op12', '2027-04-25', '2027-04-26'], ['op12', '2027-01-31', '2027-02-01'], ['op12', '2029-03-31', '2029-04-03'], ['op12', '2027-04-30', '2027-04-30'], ['op12', '2028-04-30', '2028-05-02'],
            ['none', '2028-04-15', '2028-04-15'], ['none', '2028-06-15', '2028-06-15'], ['none', '2026-06-20', '2026-06-20'], ['none', '2027-02-20', '2027-02-20'],
            ['none', '2027-08-15', '2027-08-15'], ['none', '2026-08-15', '2026-08-15'], ['none', '2026-12-25', '2026-12-25'], ['none', '2026-04-06', '2026-04-06'],
        ];
        $named = [];
        foreach ($cases as [$method, $input, $expected]) {
            $named[$input . ' ' . $method] = [$method, $input, $expected];
        }
        return $named;
    }
    /** @return array<string,array{string,string}> chwila UTC z MockClock → dzień biznesowy w Europe/Warsaw */
    public static function businessDays(): array
    {
        $cases = [['2026-10-24T22:30:00Z', '2026-10-25'], ['2026-10-25T22:30:00Z', '2026-10-25'], ['2026-03-29T21:59:59Z', '2026-03-29'], ['2026-03-29T22:00:00Z', '2026-03-30'], ['2027-03-28T22:30:00Z', '2027-03-29']];
        $named = [];
        foreach ($cases as [$instant, $expected]) {
            $named[$instant . ' businessDay'] = [$instant, $expected];
        }
        return $named;
    }
    /** @return array<int,string> Wielkanoc z docs/testing.md */
    public static function easter(): array
    {
        return ['2026' => '2026-04-05', '2027' => '2027-03-28', '2028' => '2028-04-16'];
    }
}
