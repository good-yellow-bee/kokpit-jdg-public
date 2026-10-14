<?php

declare(strict_types=1);

namespace App\Tests\Property;

use App\Calendar\PolishCalendar;
use App\Time\BusinessDate;
use Eris\Generator\ChooseGenerator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** Właściwości z „Testów własności” w docs/testing.md; ziarno z ERIS_SEED (phpunit.xml). */
final class PolishCalendarPropertyTest extends TestCase
{
    use TestTrait;
    private const DAYS_2026_2030 = 1825;
    public function testShiftLandsOnWorkingDayWithinFiveDaysAndIsIdempotent(): void
    {
        $calendar = new PolishCalendar();
        $this->forAll(new ChooseGenerator(0, self::DAYS_2026_2030))->then(function (int $offset) use ($calendar): void {
            $date = self::day($offset);
            foreach ([PolishCalendar::OP12, PolishCalendar::ZUS] as $policy) {
                $due = $calendar->shift($date, $policy);
                self::assertTrue($calendar->isWorkingDay($due));
                self::assertGreaterThanOrEqual($date, $due);
                self::assertLessThanOrEqual(self::plus($date, 5), $due);
                self::assertSame($due, $calendar->shift($due, $policy));
            }
            self::assertSame($date, $calendar->shift($date, PolishCalendar::NONE));
        });
    }
    public function testPreviousWorkingDayHasNoWorkingDayInBetween(): void
    {
        $calendar = new PolishCalendar();
        $this->forAll(new ChooseGenerator(0, self::DAYS_2026_2030))->then(function (int $offset) use ($calendar): void {
            $date = self::day($offset);
            $previous = $calendar->previousWorkingDay($date);
            self::assertTrue($calendar->isWorkingDay($previous));
            self::assertLessThan($date, $previous);
            for ($day = self::plus($previous, 1); $day < $date; $day = self::plus($day, 1)) {
                self::assertFalse($calendar->isWorkingDay($day));
            }
        });
    }
    public function testBusinessDayIsWarsawDateAndNonDecreasing(): void
    {
        $this->forAll(new ChooseGenerator(0, self::DAYS_2026_2030 * 86400), new ChooseGenerator(0, 86400 * 3))->then(function (int $seconds, int $later): void {
            $instant = (new \DateTimeImmutable('2026-01-01T00:00:00Z'))->modify('+' . $seconds . ' seconds');
            $first = (new BusinessDate(new MockClock($instant)))->today();
            self::assertSame($instant->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m-d'), $first);
            self::assertGreaterThanOrEqual($first, (new BusinessDate(new MockClock($instant->modify('+' . $later . ' seconds'))))->today());
        });
    }
    public function testEasterIsSundayBetween22MarchAnd25AprilAndCorpusChristiIsThursday(): void
    {
        $this->forAll(new ChooseGenerator(1900, 2199))->then(function (int $year): void {
            $easter = new \DateTimeImmutable(PolishCalendar::easter($year), new \DateTimeZone('UTC'));
            self::assertSame('7', $easter->format('N'));
            self::assertGreaterThanOrEqual($year . '-03-22', $easter->format('Y-m-d'));
            self::assertLessThanOrEqual($year . '-04-25', $easter->format('Y-m-d'));
            self::assertSame('4', $easter->modify('+60 days')->format('N'));
        });
    }
    private static function day(int $offset): string
    {
        return self::plus('2026-01-01', $offset);
    }
    private static function plus(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->modify('+' . $days . ' days')->format('Y-m-d');
    }
}
