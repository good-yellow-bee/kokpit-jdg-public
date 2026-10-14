<?php

declare(strict_types=1);

namespace App\Fixtures;

/** Identyfikatory fikcyjne, wyłącznie do fixtures i testów. */
final class FictionalIdentifiers
{
    public const NIPS = ['1111111111','5555555555','7777777777'];
    public const PESELS = ['11111111116'];
    /**
     * Fikcyjny numer rachunku NRB z poprawną sumą kontrolną: rozliczeniowy numer banku 99999999 nie istnieje.
     * Numer powstaje w kodzie, więc nie trafia jako literał do fixtures (skaner pada na 26 cyfrach).
     */
    public static function account(int $number): string
    {
        $rest = '99999999' . sprintf('%016d', $number);
        $value = $rest . '252100';
        $modulo = 0;
        foreach (str_split($value, 7) as $chunk) {
            $modulo = (int) ($modulo . $chunk) % 97;
        }
        return sprintf('%02d', 98 - $modulo) . $rest;
    }
    public static function validNip(string $value): bool
    {
        if (!preg_match('/^[0-9]{10}$/D', $value)) {
            return false;
        }
        $sum = 0;
        foreach ([6,5,7,2,3,4,5,6,7] as $index => $weight) {
            $sum += (int) $value[$index] * $weight;
        }
        return $sum % 11 === (int) $value[9];
    }
    public static function validPesel(string $value): bool
    {
        if (!preg_match('/^[0-9]{11}$/D', $value)) {
            return false;
        }
        $sum = 0;
        foreach ([1,3,7,9,1,3,7,9,1,3] as $index => $weight) {
            $sum += (int) $value[$index] * $weight;
        }
        return (10 - $sum % 10) % 10 === (int) $value[10];
    }
}
