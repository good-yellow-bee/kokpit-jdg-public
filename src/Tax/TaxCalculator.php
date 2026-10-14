<?php

declare(strict_types=1);

namespace App\Tax;

use App\Calendar\PolishCalendar;
use App\Config\ConnectorConfiguration;
use App\Fx\FxRateProvider;
use App\Fx\FxRateUnavailable;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;

/**
 * Kwoty F2.3 z ewidencji i `zus_payment` (docs/tax-rules.md, „Zaliczka na ryczałt”, „Składki ZUS”): zaliczka per
 * okres z nadwyżką odliczeń w roku, kwota `zus` z progiem zdrowotnej z przychodu, roczne rozliczenie zdrowotnej,
 * zestawienie PIT-28 i metryka limitu ryczałtu. Parametry tylko z `setting` (D-47).
 */
final readonly class TaxCalculator
{
    public function __construct(private Connection $db, private SettingStore $settings, private FxRateProvider $rates, private PolishCalendar $calendar, private ConnectorConfiguration $configuration) {}

    /**
     * Zaliczki roku od stycznia do okresu z `$periodStart`, z nadwyżką odliczeń przenoszoną między okresami.
     *
     * @param 'monthly'|'quarterly' $cadence
     * @return array{period_start:string,period_end:string,revenue:array<string,string>,cumulative:array<string,string>,paid_deductions:string,carried_in:string,result:RyczaltAdvance}
     */
    public function advance(string $periodStart, string $cadence = 'monthly'): array
    {
        $year = (int) substr($periodStart, 0, 4);
        $months = $cadence === 'quarterly' ? 3 : 1;
        $carry = '0.00';
        for ($month = 1; $month <= 12; $month += $months) {
            $start = sprintf('%04d-%02d-01', $year, $month);
            $end = self::monthEnd(sprintf('%04d-%02d-01', $year, $month + $months - 1));
            $revenue = $this->revenueByRate($start, $end);
            $cumulative = $this->revenueByRate(sprintf('%04d-01-01', $year), $end);
            $paid = $this->deductions($start, $end);
            $result = RyczaltAdvance::compute($revenue, $cumulative, (string) BigDecimal::of($paid)->plus($carry)->toScale(2));
            if ($start >= $periodStart) {
                return ['period_start' => $start,'period_end' => $end,'revenue' => $revenue,'cumulative' => $cumulative,'paid_deductions' => $paid,'carried_in' => $carry,'result' => $result];
            }
            $carry = $result->carryover;
        }
        throw new \InvalidArgumentException(sprintf('Okres %s poza rokiem.', $periodStart));
    }

    /**
     * Kwota `zus` za miesiąc składkowy: składki społeczne, części bez odliczenia i składka zdrowotna progu
     * z przychodu narastająco do końca miesiąca po odliczeniu zapłaconych składek społecznych. `health_bracket_manual`
     * jest dolną granicą progu, np. przy wyborze przychodu z poprzedniego roku (art. 81 ust. 2h, D-47).
     */
    public function zusAmount(string $periodStart): ?string
    {
        $parameters = $this->zusParameters($periodStart);
        if ($parameters === null) {
            return null;
        }
        $bracket = max($parameters['health_bracket_manual'], HealthContribution::bracket($parameters['health_brackets'], $this->revenueAfterSocial(substr($periodStart, 0, 4) . '-01-01', self::monthEnd($periodStart))));
        return (string) BigDecimal::of($parameters['social_pln'])->plus($parameters['other_pln'])->plus($parameters['health_brackets'][$bracket - 1]['amount_pln'])->toScale(2);
    }

    /**
     * Roczne rozliczenie składki zdrowotnej (docs/tax-rules.md, „Roczne rozliczenie zdrowotnej”): próg z przychodu
     * rocznego po odliczeniu składek społecznych, składka należna za miesiące podlegania, minus zapłacone `health_pln`.
     *
     * @return array{year:int,revenue_after_social:string,bracket:int,months:int,due:string,paid:string,difference:string,amount_due:string,refund_task:bool}|null
     */
    public function healthReconciliation(int $year): ?array
    {
        $parameters = $this->zusParameters(sprintf('%04d-12-01', $year));
        if ($parameters === null) {
            return null;
        }
        // Miesiące podlegania: miesiące roku z `zus.parameters` tego roku, czyli od pierwszych parametrów roku (D-47).
        $months = count(array_filter(range(1, 12), fn(int $month): bool => $this->zusParameters(sprintf('%04d-%02d-01', $year, $month)) !== null));
        $revenue = $this->revenueAfterSocial(sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year));
        $bracket = HealthContribution::bracket($parameters['health_brackets'], $revenue);
        $due = BigDecimal::of($parameters['health_brackets'][$bracket - 1]['amount_pln'])->multipliedBy($months);
        $paid = $this->db->fetchOne('SELECT coalesce(sum(health_pln),0) FROM zus_payment WHERE environment=? AND month BETWEEN ? AND ?', [$this->configuration->environment(), sprintf('%04d-01-01', $year), sprintf('%04d-12-01', $year)]);
        $difference = $due->minus(self::decimal($paid));
        return ['year' => $year,'revenue_after_social' => $revenue,'bracket' => $bracket,'months' => $months,'due' => (string) $due->toScale(2),'paid' => (string) self::decimal($paid)->toScale(2),
            'difference' => (string) $difference->toScale(2),'amount_due' => $difference->isPositive() ? (string) $difference->toScale(2) : '0.00','refund_task' => $difference->isNegative()];
    }

    /**
     * Zestawienie PIT-28 (wysyłka ręczna): przychód per stawka równy sumie K_8 per K_9 roku, odliczenia zapłacone w roku.
     *
     * @return array{year:int,revenue:array<string,string>,revenue_total:string,social_paid:string,health_paid:string,health_deductible:string}
     */
    public function pit28(int $year): array
    {
        $revenue = $this->revenueByRate(sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year));
        $total = BigDecimal::zero();
        foreach ($revenue as $amount) {
            $total = $total->plus($amount);
        }
        $paid = $this->db->fetchAssociative('SELECT coalesce(sum(social_pln),0) AS social, coalesce(sum(health_pln),0) AS health FROM zus_payment WHERE environment=? AND paid_on BETWEEN ? AND ?', [$this->configuration->environment(), sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)]);
        $social = self::decimal(is_array($paid) ? $paid['social'] : null);
        $health = self::decimal(is_array($paid) ? $paid['health'] : null);
        return ['year' => $year,'revenue' => $revenue,'revenue_total' => (string) $total->toScale(2),'social_paid' => (string) $social->toScale(2),'health_paid' => (string) $health->toScale(2),
            'health_deductible' => (string) $health->dividedBy(2, 2, RoundingMode::HALF_UP)];
    }

    /**
     * Metryka limitu ryczałtu (Q-38): przychód roku w euro po średnim kursie NBP z pierwszego dnia roboczego października
     * roku poprzedniego (docs/tax-rules.md, wiersz „progi w euro”) wobec limitów z `ryczalt.eligibility`.
     *
     * @return array{year:int,revenue_pln:string,rate:string,rate_table:string,rate_date:string,revenue_eur:string,limit_eur:?string,watch_limit_eur:?string,watch_used_pct:?string}
     */
    public function eligibility(int $year): array
    {
        $firstWorkingDay = $this->calendar->workingDayOnOrAfter(sprintf('%04d-10-01', $year - 1));
        $rate = $this->rates->getRate('EUR', (new \DateTimeImmutable($firstWorkingDay))->modify('+1 day')->format('Y-m-d'));
        if ($rate->effectiveDate !== $firstWorkingDay) {
            // Tabela z innego dnia niż D to błąd, nie zamiennik (docs/modules/rejestry-kursy.md).
            throw new FxRateUnavailable(sprintf('Brak tabeli A EUR z %s.', $firstWorkingDay));
        }
        $revenue = BigDecimal::zero();
        foreach ($this->revenueByRate(sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)) as $amount) {
            $revenue = $revenue->plus($amount);
        }
        $eur = $revenue->dividedBy($rate->mid, 2, RoundingMode::HALF_UP);
        $limits = $this->settings->value(SettingKeys::RYCZALT_ELIGIBILITY, sprintf('%04d-12-31', $year));
        $limit = is_array($limits) && is_string($limits['limit_eur'] ?? null) ? $limits['limit_eur'] : null;
        $watch = is_array($limits) && is_string($limits['watch_limit_eur'] ?? null) ? $limits['watch_limit_eur'] : null;
        return ['year' => $year,'revenue_pln' => (string) $revenue->toScale(2),'rate' => $rate->mid,'rate_table' => $rate->tableNo,'rate_date' => $rate->effectiveDate,'revenue_eur' => (string) $eur,
            'limit_eur' => $limit,'watch_limit_eur' => $watch,'watch_used_pct' => $watch === null ? null : (string) $eur->multipliedBy(100)->dividedBy($watch, 2, RoundingMode::HALF_UP)];
    }

    /** Przychód po odliczeniu zapłaconych składek społecznych w przedziale, nie mniej niż zero. */
    public function revenueAfterSocial(string $from, string $to): string
    {
        $environment = $this->configuration->environment();
        $revenue = $this->db->fetchOne('SELECT coalesce(sum(amount_pln),0) FROM revenue_entry WHERE environment=? AND revenue_date BETWEEN ? AND ?', [$environment, $from, $to]);
        $social = $this->db->fetchOne('SELECT coalesce(sum(social_pln),0) FROM zus_payment WHERE environment=? AND paid_on BETWEEN ? AND ?', [$environment, $from, $to]);
        $value = self::decimal($revenue)->minus(self::decimal($social));
        return (string) ($value->isNegative() ? BigDecimal::zero() : $value)->toScale(2);
    }

    /**
     * Suma K_8 per K_9 wierszy z `revenue_date` w przedziale (wszystkie rodzaje: `regular`, `correction`, `fx_difference`).
     *
     * @return array<string,string>
     */
    public function revenueByRate(string $from, string $to): array
    {
        $result = [];
        foreach ($this->db->fetchAllAssociative('SELECT rate_pct, sum(amount_pln) AS amount FROM revenue_entry WHERE environment=? AND revenue_date BETWEEN ? AND ? GROUP BY rate_pct ORDER BY rate_pct', [$this->configuration->environment(), $from, $to]) as $row) {
            $result[(string) self::decimal($row['rate_pct'])->toScale(2)] = (string) self::decimal($row['amount'])->toScale(2);
        }
        return $result;
    }

    /** Odliczenia zapłacone w przedziale: składki społeczne i 50% zdrowotnej (art. 11 ust. 1 i 1a ustawy o ryczałcie). */
    private function deductions(string $from, string $to): string
    {
        $row = $this->db->fetchAssociative('SELECT coalesce(sum(social_pln),0) AS social, coalesce(sum(health_pln),0) AS health FROM zus_payment WHERE environment=? AND paid_on BETWEEN ? AND ?', [$this->configuration->environment(), $from, $to]);
        $social = self::decimal(is_array($row) ? $row['social'] : null);
        $health = self::decimal(is_array($row) ? $row['health'] : null);
        return (string) $social->plus($health->dividedBy(2, 2, RoundingMode::HALF_UP))->toScale(2);
    }

    /**
     * `zus.parameters` obowiązujące 1. dnia miesiąca, tylko z tego samego roku: silnik nie przenosi kwot między latami.
     *
     * @return array{social_base_pln:string,social_pln:string,other_pln:string,health_brackets:list<array{revenue_up_to_pln:?string,base_pln:string,amount_pln:string}>,health_bracket_manual:int}|null
     */
    private function zusParameters(string $periodStart): ?array
    {
        $row = $this->settings->current(SettingKeys::ZUS_PARAMETERS, $periodStart);
        if ($row === null || substr($row['valid_from'], 0, 4) !== substr($periodStart, 0, 4)) {
            return null;
        }
        try {
            /** @var array{social_base_pln:string,social_pln:string,other_pln:string,health_brackets:list<array{revenue_up_to_pln:?string,base_pln:string,amount_pln:string}>,health_bracket_manual:int} $parameters */
            $parameters = SettingKeys::normalize(SettingKeys::ZUS_PARAMETERS, $row['value']);
            return $parameters;
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function monthEnd(string $monthStart): string
    {
        return (new \DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');
    }

    private static function decimal(mixed $value): BigDecimal
    {
        return is_string($value) || is_int($value) ? BigDecimal::of($value) : ($value === null ? BigDecimal::zero() : throw new \LogicException('Niepoprawna kwota.'));
    }
}
