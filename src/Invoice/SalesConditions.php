<?php

declare(strict_types=1);

namespace App\Invoice;

use App\Xml\Fa3Scenario;
use Brick\Math\BigDecimal;

/**
 * Warunki wstępne faktury sprzedaży bez stanu i bez bazy (docs/approval-gate.md, „Warunki wstępne”): scenariusz z
 * kontrahenta i weryfikacji, identyfikator nabywcy według polityki z docs/modules/rejestry-kursy.md, P_1 nie w przyszłości
 * i przegląd doradcy `ksef.fa3_review` przy celu `prod`. Wynik `null` oznacza spełniony warunek; komunikat nie ma NIP ani nazw.
 */
final class SalesConditions
{
    /**
     * `tax_scenario` z kontrahenta (docs/modules/ksef.md, „Scenariusze”): NIP → `pl_b2b`; numer VAT UE z ważnym VIES → `eu_b2b`;
     * numer VAT UE z VIES `invalid` i dowodem statusu przedsiębiorcy → `eu_b2b_unidentified`; `XI` i NrID spoza UE → `non_eu_b2b`.
     *
     * @param array{country:string,nip:?string,eu_vat_prefix:?string,eu_vat_number:?string,foreign_tax_id:?string} $party
     * @param list<array{id:string,source:?string,result:string,checked_for_date:string}> $verifications od najnowszej
     */
    public static function scenario(array $party, array $verifications): ?string
    {
        if ($party['nip'] !== null) {
            return 'pl_b2b';
        }
        if ($party['eu_vat_prefix'] !== null && $party['eu_vat_number'] !== null) {
            if (!in_array($party['eu_vat_prefix'], Subject1Importer::EU, true)) {
                return 'non_eu_b2b';
            }
            $vies = self::first($verifications, 'vies');
            $manual = self::first($verifications, null);
            return $vies !== null && $vies['result'] === 'invalid' && $manual !== null ? 'eu_b2b_unidentified' : 'eu_b2b';
        }
        if ($party['foreign_tax_id'] !== null) {
            return in_array($party['country'] === 'GR' ? 'EL' : $party['country'], Subject1Importer::EU, true) ? 'eu_b2b_unidentified' : 'non_eu_b2b';
        }
        return null;
    }

    /**
     * Onboarding ukończony (docs/modules/freshbooks.md, „Klient i kontrahent”): kraj i identyfikator podatkowy oraz wiersz
     * `verification` wymagany dla typu kontrahenta: biała lista albo dowód przy NIP, VIES albo dowód przy numerze VAT UE,
     * dowód ręczny przy NrID spoza UE.
     *
     * @param array{country:string,nip:?string,eu_vat_prefix:?string,eu_vat_number:?string,foreign_tax_id:?string} $party
     * @param list<array{id:string,source:?string,result:string,checked_for_date:string}> $verifications
     */
    public static function onboarded(array $party, array $verifications): bool
    {
        return match (true) {
            $party['nip'] !== null => self::first($verifications, 'white_list') !== null || self::first($verifications, null) !== null,
            $party['eu_vat_number'] !== null => self::first($verifications, 'vies') !== null || self::first($verifications, null) !== null,
            $party['foreign_tax_id'] !== null => self::first($verifications, null) !== null,
            default => false,
        };
    }

    /**
     * Identyfikator nabywcy przy prośbie i decyzji (D-05): `eu_b2b` tylko z VIES `valid` z dzisiejszego dnia; `pl_b2b` z białą
     * listą `valid` nie starszą niż `$plMaxAge` albo z dowodem ręcznym; `non_eu_b2b` z dowodem ręcznym; `eu_b2b_unidentified`
     * zablokowany do przeglądu doradcy (Q-29).
     *
     * @param list<array{id:string,source:?string,result:string,checked_for_date:string}> $verifications od najnowszej
     * @return array{?string,?string} problem albo null; `verification.id` użyty przy zatwierdzeniu
     */
    public static function buyerProblem(string $scenario, array $verifications, string $today, \DateInterval $plMaxAge): array
    {
        switch ($scenario) {
            case 'eu_b2b':
                $vies = self::first($verifications, 'vies');
                $manual = self::first($verifications, null);
                // Brak odpowiedzi VIES: dowód ręczny z formularza VIES z numerem konsultacji z tego samego dnia (docs/modules/ksef.md).
                if (($vies === null || $vies['result'] === 'unverified_retry') && $manual !== null && $manual['checked_for_date'] === $today) {
                    return [null, $manual['id']];
                }
                if ($vies === null || $vies['result'] !== 'valid') {
                    return [sprintf('Identyfikator nabywcy z UE bez ważnego wyniku VIES (%s); prośba i decyzja czekają na VIES valid (D-05).', $vies['result'] ?? 'brak sprawdzenia'), null];
                }
                if ($vies['checked_for_date'] !== $today) {
                    return [sprintf('Wynik VIES z %s; prośba i decyzja wymagają sprawdzenia z dnia %s.', $vies['checked_for_date'], $today), null];
                }
                return [null, $vies['id']];
            case 'pl_b2b':
                foreach ($verifications as $row) {
                    if ($row['source'] === null) {
                        return [null, $row['id']];
                    }
                    if ($row['source'] === 'white_list') {
                        $limit = (new \DateTimeImmutable($today))->sub($plMaxAge)->format('Y-m-d');
                        if ($row['result'] === 'valid' && $row['checked_for_date'] >= $limit) {
                            return [null, $row['id']];
                        }
                        return [sprintf('NIP nabywcy bez aktualnego wyniku białej listy (%s z %s); potrzebne nowe sprawdzenie albo manual_evidence.', $row['result'], $row['checked_for_date']), null];
                    }
                }
                return ['NIP nabywcy bez wyniku białej listy i bez manual_evidence (D-05).', null];
            case 'non_eu_b2b':
                $manual = self::first($verifications, null);
                return $manual === null ? ['Identyfikator nabywcy spoza UE bez dowodu manual_evidence (D-05).', null] : [null, $manual['id']];
            default:
                return ['Scenariusz eu_b2b_unidentified jest zablokowany do potwierdzenia doradcy w ksef.fa3_review (Q-29, D-05).', null];
        }
    }

    /** P_1 nie może być późniejsza niż dzisiejszy dzień w Europe/Warsaw (docs/modules/ksef.md, „Reguła P_1”). */
    public static function p1Problem(string $p1, string $today): ?string
    {
        return $p1 > $today ? sprintf('P_1 %s jest w przyszłości (dziś %s); prośba i decyzja zablokowane (D-16).', $p1, $today) : null;
    }

    /**
     * Przegląd doradcy (D-04): przy celu `prod` `tax_scenario` faktury musi być w `ksef.fa3_review.scenarios`, a KOR wymaga
     * `kor` = tak. Przy celach `test` i `demo` warunek jest pominięty. Cel przychodzi jako argument, nigdy ze zmiennej.
     */
    public static function fa3ReviewProblem(string $target, mixed $review, string $scenario, string $kind): ?string
    {
        if ($target !== 'prod') {
            return null;
        }
        if (!is_array($review) || !is_array($review['scenarios'] ?? null)) {
            return 'Brak wpisu ksef.fa3_review: przy KSEF_TARGET=prod decyzja czeka na przegląd doradcy (D-04).';
        }
        if (!in_array($scenario, $review['scenarios'], true)) {
            return sprintf('Scenariusz %s nie jest zatwierdzony w ksef.fa3_review (D-04).', $scenario);
        }
        if ($kind === 'KOR' && ($review['kor'] ?? false) !== true) {
            return 'KOR nie jest zatwierdzona w ksef.fa3_review (D-04).';
        }
        return null;
    }

    /**
     * Kod P_12 pozycji: `np II` przy `eu_b2b`, `np I` poza UE i przy nabywcy niezidentyfikowanym, przy `pl_b2b` stawka
     * `vat.standard_rate` z dnia usługi bez zer końcowych (np. `23`); null, gdy scenariusza albo stawki brak.
     */
    public static function vatCode(?string $scenario, mixed $standardRate): ?string
    {
        $fa3 = $scenario === null ? null : Fa3Scenario::tryFrom($scenario);
        if ($fa3 === null) {
            return null;
        }
        return $fa3->outOfScopeCode() ?? (is_string($standardRate) ? (string) BigDecimal::of($standardRate)->strippedOfTrailingZeros() : null);
    }

    /**
     * @param list<array{id:string,source:?string,result:string,checked_for_date:string}> $verifications
     * @return array{id:string,source:?string,result:string,checked_for_date:string}|null
     */
    private static function first(array $verifications, ?string $source): ?array
    {
        foreach ($verifications as $row) {
            if ($row['source'] === $source && ($source !== null || $row['result'] === 'manual_evidence')) {
                return $row;
            }
        }
        return null;
    }
}
