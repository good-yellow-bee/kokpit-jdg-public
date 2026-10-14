<?php

declare(strict_types=1);

namespace App\Architecture;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * Budżet przebiegu live dla konektora `claude` (`chat.live_run_budget_usd`, zmienna `CHAT_LIVE_RUN_BUDGET_USD`, D-60).
 * Bootstrap testsuite `live` bez budżetu odmawia startu; po wyczerpaniu budżetu kolejne testy `claude` są pomijane
 * przed żądaniem. Koszt każdego żądania dopisuje test z `usage` odpowiedzi (`ChatBudget::cost`).
 */
final class LiveRunBudget
{
    public const VARIABLE = 'CHAT_LIVE_RUN_BUDGET_USD';
    private static ?self $current = null;
    private BigDecimal $spent;

    public function __construct(private readonly BigDecimal $limit)
    {
        if (!$limit->isPositive()) {
            throw new \RuntimeException('chat.live_run_budget_usd musi być dodatni.');
        }
        $this->spent = BigDecimal::zero();
    }

    /** @param array<string,string|false> $environment */
    public static function fromEnvironment(array $environment): self
    {
        $value = $environment[self::VARIABLE] ?? false;
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,5})(\.[0-9]{1,6})?$/D', $value)) {
            throw new \RuntimeException('Live tests require chat.live_run_budget_usd (CHAT_LIVE_RUN_BUDGET_USD), e.g. 0.25.');
        }
        try {
            return new self(BigDecimal::of($value));
        } catch (MathException $exception) {
            throw new \RuntimeException('chat.live_run_budget_usd is not a decimal.', 0, $exception);
        }
    }

    public static function start(self $budget): void
    {
        self::$current = $budget;
    }

    /** Tylko dla testów samego strażnika: przywraca stan sprzed testu. */
    public static function restore(?self $budget): void
    {
        self::$current = $budget;
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    public function record(BigDecimal $cost): void
    {
        $this->spent = $this->spent->plus($cost);
    }

    public function exhausted(): bool
    {
        return $this->spent->isGreaterThanOrEqualTo($this->limit);
    }

    public function spent(): BigDecimal
    {
        return $this->spent;
    }
}
