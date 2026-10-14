<?php

declare(strict_types=1);

namespace App\Tax;

use Brick\Math\BigDecimal;

/**
 * Próg składki zdrowotnej na ryczałcie (docs/tax-rules.md, „Składki ZUS”): przychód narastająco po odliczeniu zapłaconych
 * składek społecznych (art. 81 ust. 2g ustawy o świadczeniach) wobec `health_brackets[]` z `zus.parameters`.
 * Górna granica progu jest włącznie („do 60 000 zł”); ostatni próg ma `revenue_up_to_pln` = null.
 */
final readonly class HealthContribution
{
    /**
     * @param list<array{revenue_up_to_pln:?string,base_pln:string,amount_pln:string}> $brackets
     * @return int numer progu od 1
     */
    public static function bracket(array $brackets, string $revenueAfterSocial): int
    {
        if ($brackets === []) {
            throw new \InvalidArgumentException('zus.parameters bez progów zdrowotnej.');
        }
        $revenue = BigDecimal::of($revenueAfterSocial);
        foreach ($brackets as $i => $bracket) {
            if ($bracket['revenue_up_to_pln'] === null || $revenue->isLessThanOrEqualTo($bracket['revenue_up_to_pln'])) {
                return $i + 1;
            }
        }
        return count($brackets);
    }

    /** @param list<array{revenue_up_to_pln:?string,base_pln:string,amount_pln:string}> $brackets */
    public static function amount(array $brackets, string $revenueAfterSocial): string
    {
        return $brackets[self::bracket($brackets, $revenueAfterSocial) - 1]['amount_pln'];
    }
}
