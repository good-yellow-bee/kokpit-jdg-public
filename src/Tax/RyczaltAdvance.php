<?php

declare(strict_types=1);

namespace App\Tax;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Wzór zaliczki na ryczałt dla jednego okresu (docs/tax-rules.md, „Zaliczka na ryczałt”): odliczenia dzielone
 * w proporcji przychodu narastająco per stawka (art. 11 ust. 3), podstawa per stawka w pełnych złotych, zaliczka
 * w pełnych złotych (OP art. 63 § 1), nadwyżka odliczeń przechodzi na kolejne okresy roku.
 */
final readonly class RyczaltAdvance
{
    /** Skala udziału `u_r`; wynik zaokrągla się dopiero na podstawie. */
    private const SHARE_SCALE = 12;

    /**
     * @param array<string,string> $shares udział narastający per stawka
     * @param array<string,string> $deductions odliczenie per stawka (`O` × `u_r`, do grosza)
     * @param array<string,string> $bases podstawa `B_r` w pełnych złotych
     * @param array<string,string> $taxes ryczałt per stawka przed zaokrągleniem
     */
    public function __construct(
        public array $shares,
        public array $deductions,
        public array $bases,
        public array $taxes,
        public string $advance,
        public string $carryover,
    ) {}

    /**
     * @param array<string,string> $revenue `P_r`: przychód okresu per stawka (np. "12.00" → "20000.00")
     * @param array<string,string> $cumulative przychód narastająco od 1 stycznia do końca okresu per stawka
     * @param string $deductions `O`: odliczenia zapłacone w okresie plus nadwyżka z poprzednich okresów roku
     */
    public static function compute(array $revenue, array $cumulative, string $deductions): self
    {
        $total = BigDecimal::zero();
        foreach ($cumulative as $amount) {
            $total = $total->plus($amount);
        }
        $o = BigDecimal::of($deductions);
        $rates = array_values(array_unique([...array_keys($revenue), ...array_keys($cumulative)]));
        sort($rates, SORT_STRING);
        if (!$total->isPositive()) {
            // Przychód narastająco = 0: podstawy zerowe, całe `O` przechodzi (krok 7).
            $zeros = array_fill_keys($rates, '0');
            return new self($zeros, $zeros, $zeros, $zeros, '0', (string) $o->toScale(2, RoundingMode::HALF_UP));
        }
        $shares = $parts = $bases = $taxes = [];
        $tax = BigDecimal::zero();
        $carry = BigDecimal::zero();
        foreach ($rates as $rate) {
            $share = BigDecimal::of($cumulative[$rate] ?? '0')->dividedBy($total, self::SHARE_SCALE, RoundingMode::HALF_UP);
            $part = $o->multipliedBy($share);
            $income = BigDecimal::of($revenue[$rate] ?? '0');
            $base = $income->minus($part);
            $base = $base->isNegative() ? BigDecimal::zero() : $base->toScale(0, RoundingMode::HALF_UP);
            $rateTax = $base->multipliedBy(BigDecimal::of($rate)->dividedBy(100, 4, RoundingMode::UNNECESSARY));
            $over = $part->minus($income);
            if ($over->isPositive()) {
                $carry = $carry->plus($over);
            }
            $shares[$rate] = (string) $share->strippedOfTrailingZeros();
            $parts[$rate] = (string) $part->toScale(2, RoundingMode::HALF_UP);
            $bases[$rate] = (string) $base;
            $taxes[$rate] = (string) $rateTax->strippedOfTrailingZeros();
            $tax = $tax->plus($rateTax);
        }
        return new self($shares, $parts, $bases, $taxes, (string) $tax->toScale(0, RoundingMode::HALF_UP), (string) $carry->toScale(2, RoundingMode::HALF_UP));
    }
}
