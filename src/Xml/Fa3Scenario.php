<?php

declare(strict_types=1);

namespace App\Xml;

/** `invoice.tax_scenario` i jego mapowanie FA(3) (docs/modules/ksef.md, „Mapowanie FA(3): scenariusze B2B”). */
enum Fa3Scenario: string
{
    case EuB2b = 'eu_b2b';
    case EuB2bUnidentified = 'eu_b2b_unidentified';
    case NonEuB2b = 'non_eu_b2b';
    case PlB2b = 'pl_b2b';

    /** P_12 każdej pozycji przy usłudze poza terytorium kraju; null oznacza stawkę krajową z pozycji. */
    public function outOfScopeCode(): ?string
    {
        return match ($this) {
            self::EuB2b => 'np II',
            self::EuB2bUnidentified, self::NonEuB2b => 'np I',
            self::PlB2b => null,
        };
    }

    /**
     * P_18 („odwrotne obciążenie”): 1 przy `eu_b2b`, 2 przy `pl_b2b`. `non_eu_b2b`: założenie F3.1 = 1 (nabywca rozlicza
     * VAT albo podatek o podobnym charakterze) do przeglądu doradcy (Q-29). `eu_b2b_unidentified` nie ma mapowania do Q-29.
     */
    public function reverseCharge(): ?int
    {
        return match ($this) {
            self::EuB2b, self::NonEuB2b => 1,
            self::PlB2b => 2,
            self::EuB2bUnidentified => null,
        };
    }
}
