<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tax\RyczaltAdvance;
use App\Tax\ThresholdForecast;
use PHPUnit\Framework\TestCase;

/** Stawki we wzorze zaliczki i run-rate prognozy na brzegach wejścia. Dane fikcyjne. */
final class RyczaltAdvanceRatesTest extends TestCase
{
    public function testRatesAreSortedAsStringsWhateverTheInputOrder(): void
    {
        $result = RyczaltAdvance::compute(['8.50' => '5000.00','12.00' => '20000.00'], ['8.50' => '5000.00','12.00' => '20000.00'], '2015.29');
        self::assertSame(['12.00' => '18388','8.50' => '4597'], $result->bases);
        self::assertSame(['12.00', '8.50'], array_keys($result->taxes));
    }

    public function testRateOnlyInPeriodRevenueStillGetsBaseAndTax(): void
    {
        $result = RyczaltAdvance::compute(['17.00' => '100.00'], ['12.00' => '1000.00'], '0.00');
        self::assertSame(['12.00' => '0','17.00' => '100'], $result->bases);
        self::assertSame('17', $result->advance);
        $zero = RyczaltAdvance::compute(['17.00' => '0.00'], [], '10.00');
        self::assertSame(['17.00' => '0'], $zero->bases);
    }

    public function testRateWithHundredthsOfPercentIsExact(): void
    {
        // Fikcyjna stawka 2,25%: 1 000 zł × 0,0225 = 22,5 zł, zaliczka 23 zł.
        $result = RyczaltAdvance::compute(['2.25' => '1000.00'], ['2.25' => '1000.00'], '0.00');
        self::assertSame(['2.25' => '22.5'], $result->taxes);
        self::assertSame('23', $result->advance);
    }

    public function testRunRateAcceptsSingleBasisMonth(): void
    {
        self::assertSame('1234.56', ThresholdForecast::runRate([1 => '1234.56'], 1));
        self::assertSame('1234.56', ThresholdForecast::cumulative([1 => '1234.56'], 1)[0]);
        $this->expectException(\InvalidArgumentException::class);
        ThresholdForecast::runRate([], 0);
    }
}
