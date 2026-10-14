<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tax\HealthContribution;
use App\Tax\RyczaltAdvance;
use App\Tests\Support\EvidenceArtefacts;
use PHPUnit\Framework\TestCase;

/** Wzór zaliczki i progi zdrowotnej z przykładów w docs/tax-rules.md (kryteria `ryczalt-advance`, `ryczalt-advance-carryover`, `health-threshold`). Dane fikcyjne. */
final class RyczaltAdvanceTest extends TestCase
{
    /** Progi 2026 z tabeli „Składka zdrowotna na ryczałcie w 2026” w docs/tax-rules.md; w kodzie tylko z `zus.parameters`. */
    private const BRACKETS = [
        ['revenue_up_to_pln' => '60000.00','base_pln' => '5537.18','amount_pln' => '498.35'],
        ['revenue_up_to_pln' => '300000.00','base_pln' => '9228.64','amount_pln' => '830.58'],
        ['revenue_up_to_pln' => null,'base_pln' => '16611.55','amount_pln' => '1495.04'],
    ];

    public function testRyczaltAdvanceMatchesTaxRulesExample(): void
    {
        // Społeczne 1 600,00 + 50% z 830,58 = 2 015,29; przychód 20 000,00 (12%) i 5 000,00 (8,5%).
        $result = RyczaltAdvance::compute(['12.00' => '20000.00','8.50' => '5000.00'], ['12.00' => '20000.00','8.50' => '5000.00'], '2015.29');
        self::assertSame(['12.00' => '0.8','8.50' => '0.2'], $result->shares);
        self::assertSame(['12.00' => '1612.23','8.50' => '403.06'], $result->deductions);
        self::assertSame(['12.00' => '18388','8.50' => '4597'], $result->bases);
        self::assertSame(['12.00' => '2206.56','8.50' => '390.745'], $result->taxes);
        self::assertSame('2597', $result->advance);
        self::assertSame('0.00', $result->carryover);
        self::golden('ryczalt-advance', "12.00;share=0.8;deduction=1612.23;base=18388;tax=2206.56\n8.50;share=0.2;deduction=403.06;base=4597;tax=390.745\nadvance=2597;carryover=0.00\n", self::lines($result));
    }

    public function testRyczaltAdvanceCarryoverMovesExcessDeductionsToNextMonth(): void
    {
        $result = RyczaltAdvance::compute(['12.00' => '1000.00'], ['12.00' => '1000.00'], '2015.29');
        self::assertSame(['12.00' => '0'], $result->bases);
        self::assertSame('0', $result->advance);
        self::assertSame('1015.29', $result->carryover);
        // Kolejny miesiąc: 10 000,00 zł przychodu, odliczenia tylko z nadwyżki.
        $next = RyczaltAdvance::compute(['12.00' => '10000.00'], ['12.00' => '11000.00'], $result->carryover);
        self::assertSame(['12.00' => '8985'], $next->bases);
        self::assertSame('1078', $next->advance);
        self::golden('ryczalt-advance-carryover', "12.00;share=1;deduction=2015.29;base=0;tax=0\nadvance=0;carryover=1015.29\n12.00;share=1;deduction=1015.29;base=8985;tax=1078.2\nadvance=1078;carryover=0.00\n", self::lines($result) . self::lines($next));
    }

    public function testRyczaltAdvanceWithoutRevenueCarriesAllDeductions(): void
    {
        $result = RyczaltAdvance::compute([], [], '1612.23');
        self::assertSame(['0', '1612.23'], [$result->advance, $result->carryover]);
    }

    public function testHealthThresholdBracketsAfterSocialContributions(): void
    {
        $amounts = [];
        foreach (['59999.99', '60000.00', '60000.01', '299999.99', '300000.01'] as $revenue) {
            $amounts[$revenue] = HealthContribution::amount(self::BRACKETS, $revenue);
        }
        self::assertSame(['59999.99' => '498.35','60000.00' => '498.35','60000.01' => '830.58','299999.99' => '830.58','300000.01' => '1495.04'], $amounts);
        $actual = implode("\n", array_map(static fn(string $revenue, string $amount): string => $revenue . ';' . $amount, array_keys($amounts), $amounts)) . "\n";
        self::golden('health-threshold', "59999.99;498.35\n60000.00;498.35\n60000.01;830.58\n299999.99;830.58\n300000.01;1495.04\n", $actual);
    }

    /** Złoty plik jako `golden/<name>.diff`: pusty przy zgodności z przykładem z docs/tax-rules.md. */
    private static function golden(string $name, string $expected, string $actual): void
    {
        EvidenceArtefacts::write('golden/' . $name . '.diff', $expected === $actual ? '' : "--- oczekiwane\n+++ wynik\n-" . $expected . '+' . $actual);
        self::assertSame($expected, $actual);
    }

    private static function lines(RyczaltAdvance $result): string
    {
        $lines = [];
        foreach ($result->bases as $rate => $base) {
            $lines[] = sprintf('%s;share=%s;deduction=%s;base=%s;tax=%s', $rate, $result->shares[$rate], $result->deductions[$rate], $base, $result->taxes[$rate]);
        }
        $lines[] = sprintf('advance=%s;carryover=%s', $result->advance, $result->carryover);
        return implode("\n", $lines) . "\n";
    }
}
