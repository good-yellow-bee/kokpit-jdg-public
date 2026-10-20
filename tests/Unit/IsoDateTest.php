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

    public function testOtherFormatIsRefused(): void
    {
        foreach (['20260131', '2026-01', 'x2026-01-31', '2026-01-31x', "2026-01-31\n", '2026-01-31T10:00:00'] as $date) {
            try {
                IsoDate::year($date);
                self::fail('Przyjęto ' . $date);
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Data poza formatem RRRR-MM-DD.', $exception->getMessage());
            }
        }
    }
}
