<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Calendar\PolishCalendar;
use PHPUnit\Framework\TestCase;

final class PolishCalendarTest extends TestCase
{
    public function testPreviousWorkingDaySkipsChristmasEveAndNewYear(): void
    {
        $calendar = new PolishCalendar();
        self::assertSame('2025-12-23', $calendar->previousWorkingDay('2025-12-29'));
        self::assertSame('2026-12-31', $calendar->previousWorkingDay('2027-01-04'));
    }
    public function testHolidays2026MatchCompliancecalendarTable(): void
    {
        self::assertSame(['2026-01-01','2026-01-06','2026-04-05','2026-04-06','2026-05-01','2026-05-03','2026-05-24','2026-06-04','2026-08-15','2026-11-01','2026-11-11','2026-12-24','2026-12-25','2026-12-26'], (new PolishCalendar())->holidays(2026));
        self::assertSame(['2027-03-28','2027-03-29','2027-05-16','2027-05-27'], array_values(array_intersect((new PolishCalendar())->holidays(2027), ['2027-03-28','2027-03-29','2027-05-16','2027-05-27'])));
    }
    public function testChristmasEveIsWorkingDayBefore2025(): void
    {
        $calendar = new PolishCalendar();
        self::assertTrue($calendar->isWorkingDay('2024-12-24'));
        self::assertFalse($calendar->isWorkingDay('2025-12-24'));
    }
    public function testSaturdayIsNotHolidayButNotWorkingDay(): void
    {
        $calendar = new PolishCalendar();
        self::assertFalse($calendar->isHoliday('2026-11-07'));
        self::assertFalse($calendar->isWorkingDay('2026-11-07'));
    }
    public function testRejectsUnknownPolicyAndMalformedDate(): void
    {
        $calendar = new PolishCalendar();
        try {
            $calendar->shift('2026-01-01', 'weekend');
            self::fail('Nieznana polityka musi dać wyjątek.');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        $calendar->isWorkingDay('2026-02-30');
    }
}
