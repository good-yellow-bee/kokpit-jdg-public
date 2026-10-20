<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Calendar\IsoDate;
use PHPUnit\Framework\TestCase;

final class IsoDateTest extends TestCase
{
    public function testYearAndMonthOfLegalDate(): void
    {
        self::assertSame([2026, 1], [IsoDate::year('2026-01-31'), IsoDate::month('2026-01-31')]);
        self::assertSame([2027, 12], [IsoDate::year('2027-12-05'), IsoDate::month('2027-12-05')]);
    }
}
