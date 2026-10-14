<?php

declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use App\Calendar\PolishCalendar;
use App\Tests\Support\CalendarGoldenCases;
use App\Time\BusinessDate;
use Symfony\Component\Clock\MockClock;

// Wyjście kryteriów calendar-golden i calendar-property (docs/phases/f0b-bramka-kalendarz.md); PHPUnit uruchamia przypadki osobno.
$scenario = $argv[1] ?? '';
if ($scenario === 'golden') {
    $calendar = new PolishCalendar();
    $ok = true;
    echo "data;polityka albo metoda;wynik;oczekiwany\n";
    foreach (CalendarGoldenCases::dates() as [$method, $input, $expected]) {
        $actual = $method === 'previousWorkingDay' ? $calendar->previousWorkingDay($input) : $calendar->shift($input, $method);
        $ok = $ok && $actual === $expected;
        printf("%s;%s;%s;%s\n", $input, $method, $actual, $expected);
    }
    foreach (CalendarGoldenCases::businessDays() as [$instant, $expected]) {
        $actual = (new BusinessDate(new MockClock($instant)))->today();
        $ok = $ok && $actual === $expected;
        printf("%s;dzień biznesowy Europe/Warsaw;%s;%s\n", $instant, $actual, $expected);
    }
    foreach (CalendarGoldenCases::easter() as $year => $expected) {
        $actual = PolishCalendar::easter($year);
        $ok = $ok && $actual === $expected;
        printf("%s;Wielkanoc;%s;%s\n", $year, $actual, $expected);
    }
    echo 'Złote przypadki: ' . ($ok ? 'zgodne' : 'RÓŻNICE') . "\n";
    exit($ok ? 0 : 1);
}
if ($scenario === 'property') {
    $config = simplexml_load_file(dirname(__DIR__) . '/phpunit.xml');
    $seed = $config === false ? '' : (string) ($config->xpath('//env[@name="ERIS_SEED"]/@value')[0] ?? '');
    // Eris: 100 prób na właściwość, chyba że test ustawia inaczej.
    printf("Ziarno ERIS_SEED: %s; próby: 100 na właściwość; właściwości: przesunięcie op12/zus/none, previousWorkingDay, dzień biznesowy, Wielkanoc i Boże Ciało\n", $seed);
    exit($seed === '' ? 1 : 0);
}
throw new InvalidArgumentException('Scenariusz golden albo property.');
