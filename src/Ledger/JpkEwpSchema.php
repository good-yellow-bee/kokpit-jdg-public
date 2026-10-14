<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Xml\XsdDictionary;
use Brick\Math\BigDecimal;

/** Pliki i typy JPK_EWP(4) w `resources/xsd`; słowniki K_6 i K_9 pochodzą z XSD. */
final readonly class JpkEwpSchema
{
    public const SCHEMA = 'Schemat_JPK_EWP(4)_v1-0.xsd';
    public const COUNTRIES = 'KodyKrajow_v10-0E.xsd';
    public function __construct(private XsdDictionary $dictionary) {}
    /** Kod słownika K_9 równy stawce albo null, gdy stawki nie ma w słowniku. */
    public function rateCode(BigDecimal $rate): ?string
    {
        foreach ($this->dictionary->enumerations(self::SCHEMA, 'TStawkaPodatku') as $code) {
            if (BigDecimal::of($code)->isEqualTo($rate)) {
                return $code;
            }
        }
        return null;
    }
    public function isCountry(string $code): bool
    {
        return in_array($code, $this->dictionary->enumerations(self::COUNTRIES, 'TKodKraju'), true);
    }
    public function isKsefNumber(string $value): bool
    {
        return $this->dictionary->matchesPattern(self::SCHEMA, 'TNumerKSeF', $value);
    }
}
