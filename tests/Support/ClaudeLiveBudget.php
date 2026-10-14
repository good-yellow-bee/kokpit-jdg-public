<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Architecture\LiveRunBudget;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Assert;

/**
 * Budżet przebiegu live konektora `claude` w testach `tests/Live` (D-60): `guard()` przed każdym żądaniem pomija test
 * po wyczerpaniu budżetu, `record()` dopisuje koszt odpowiedzi (`ChatBudget::cost` z `usage`).
 */
final class ClaudeLiveBudget
{
    public static function guard(): void
    {
        $budget = LiveRunBudget::current() ?? throw new \RuntimeException('Brak budżetu przebiegu live chat.live_run_budget_usd.');
        if ($budget->exhausted()) {
            Assert::markTestSkipped(sprintf('Budżet przebiegu live wyczerpany (%s USD); test claude pominięty bez żądania.', $budget->spent()));
        }
    }

    public static function record(BigDecimal $cost): void
    {
        (LiveRunBudget::current() ?? throw new \RuntimeException('Brak budżetu przebiegu live.'))->record($cost);
    }
}
