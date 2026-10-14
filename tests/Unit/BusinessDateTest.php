<?php

namespace App\Tests\Unit;

use App\Time\BusinessDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class BusinessDateTest extends TestCase
{
    /** @return iterable<string,array{string,string,int}> */
    public static function dates(): iterable
    {
        yield 'before midnight' => ['2026-10-09T21:59:59Z','2026-10-09',24];
        yield 'midnight' => ['2026-10-09T22:00:00Z','2026-10-10',24];
        yield 'spring DST' => ['2026-03-29T01:30:00Z','2026-03-29',23];
        yield 'autumn first hour' => ['2026-10-25T00:30:00Z','2026-10-25',25];
        yield 'autumn repeated hour' => ['2026-10-25T01:30:00Z','2026-10-25',25];
    }
    #[DataProvider('dates')] public function testWarsawBusinessDay(string $instant, string $date, int $hours): void
    {
        $dates = new BusinessDate(new MockClock($instant));
        self::assertSame($date, $dates->today());
        self::assertSame($hours * 3600, $dates->nextMidnight()->getTimestamp() - $dates->midnight()->getTimestamp());
    }
}
