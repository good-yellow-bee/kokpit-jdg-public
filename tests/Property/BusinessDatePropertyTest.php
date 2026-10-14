<?php

declare(strict_types=1);

namespace App\Tests\Property;

use App\Time\BusinessDate;
use Eris\TestTrait;
use Eris\Generator\ChooseGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class BusinessDatePropertyTest extends TestCase
{
    use TestTrait;
    public function testEveryGeneratedInstantBelongsToExactlyOneWarsawBusinessDay(): void
    {
        $this->forAll(new ChooseGenerator(0, 365 * 86400))->then(function (int $offset): void {
            $instant = (new \DateTimeImmutable('2026-01-01T00:00:00Z'))->modify('+' . $offset . ' seconds');
            $date = new BusinessDate(new MockClock($instant));
            self::assertSame($instant->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m-d'), $date->today());
            self::assertLessThanOrEqual($instant, $date->midnight());
            self::assertGreaterThan($instant, $date->nextMidnight());
            self::assertContains($date->nextMidnight()->getTimestamp() - $date->midnight()->getTimestamp(), [82800,86400,90000]);
        });
    }
}
