<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Calendar\PolishCalendar;
use App\Tests\Support\CalendarGoldenCases;
use App\Time\BusinessDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class PolishCalendarGoldenTest extends TestCase
{
    /** @return array<string,array{string,string,string}> */
    public static function dates(): array
    {
        return CalendarGoldenCases::dates();
    }
    /** @return array<string,array{string,string}> */
    public static function businessDays(): array
    {
        return CalendarGoldenCases::businessDays();
    }
    #[DataProvider('dates')]
    public function testDate(string $method, string $input, string $expected): void
    {
        $calendar = new PolishCalendar();
        self::assertSame($expected, $method === 'previousWorkingDay' ? $calendar->previousWorkingDay($input) : $calendar->shift($input, $method));
    }
    #[DataProvider('businessDays')]
    public function testBusinessDay(string $instant, string $expected): void
    {
        self::assertSame($expected, (new BusinessDate(new MockClock($instant)))->today());
    }
    public function testEasterDates(): void
    {
        foreach (CalendarGoldenCases::easter() as $year => $date) {
            self::assertSame($date, PolishCalendar::easter($year));
        }
    }
}
