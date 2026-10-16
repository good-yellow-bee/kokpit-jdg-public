<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tax\ThresholdForecast;
use PHPUnit\Framework\TestCase;

/** Run-rate prognozy progów (D-76): średnia z miesięcy podstawy, wartości narastająco i miesiąc przekroczenia. Kwoty fikcyjne. */
final class ThresholdForecastTest extends TestCase
{
    public function testRunRateAveragesBasisMonthsAndTreatsMissingMonthAsZero(): void
    {
        self::assertSame('6666.67', ThresholdForecast::runRate([1 => '10000.00', 3 => '10000.00'], 3));
        self::assertSame('10000.00', ThresholdForecast::runRate([1 => '10000.00', 2 => '10000.00', 3 => '99999.00'], 2));
    }

    public function testCumulativeKeepsActualMonthsThenAddsRunRate(): void
    {
        $cumulative = ThresholdForecast::cumulative([1 => '12000.00', 2 => '10400.00', 3 => '10400.00', 4 => '10400.00'], 4);
        self::assertCount(12, $cumulative);
        self::assertSame(['12000.00', '22400.00', '32800.00', '43200.00', '54000.00', '64800.00'], array_slice($cumulative, 0, 6));
        self::assertSame('129600.00', $cumulative[11]);
        self::assertSame(['1000.00', '1500.00'], array_slice(ThresholdForecast::cumulative([1 => '1000.00', 2 => '500.00'], 12), 0, 2));
    }

    public function testCrossMonthIsFirstMonthAboveLimit(): void
    {
        $cumulative = ThresholdForecast::cumulative([1 => '12000.00', 2 => '10400.00', 3 => '10400.00', 4 => '10400.00'], 4);
        self::assertSame(6, ThresholdForecast::crossMonth($cumulative, '60000.00'));
        // Granica progu jest włącznie: wartość równa limitowi go nie przekracza.
        self::assertSame(5, ThresholdForecast::crossMonth($cumulative, '43200.00'));
        self::assertNull(ThresholdForecast::crossMonth($cumulative, '129600.00'));
    }

    public function testBasisOutsideYearIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ThresholdForecast::runRate([], 0);
    }
}
