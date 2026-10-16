<?php

declare(strict_types=1);

namespace App\Tax;

use App\Config\ConnectorConfiguration;
use App\Fx\FxRateUnavailable;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use App\Time\BusinessDate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;

/**
 * Prognoza progów i rezerwa podatkowa (roadmap, priorytet 5; D-76, docs/tax-rules.md, „Prognoza progów”): run-rate
 * przychodu z zakończonych miesięcy roku wobec limitów w euro z `ryczalt.eligibility` i progów zdrowotnej
 * z `zus.parameters`, plus kwota do odłożenia dziś. To prognoza, nie porada; kokpit tylko liczy.
 */
final readonly class ThresholdForecast
{
    /** Pola `ryczalt.eligibility` w kolejności na panelu. */
    private const EUR_LIMITS = ['limit_eur' => 'eligibility','watch_limit_eur' => 'eligibility_watch','quarterly_limit_eur' => 'quarterly'];

    public function __construct(private TaxCalculator $tax, private SettingStore $settings, private BusinessDate $date, private Connection $db, private ConnectorConfiguration $configuration) {}

    /**
     * Prognoza roku `$year`. Bez zakończonego miesiąca (styczeń bieżącego roku) nie ma podstawy, więc wynik to null.
     *
     * @return array{year:int,basis_months:int,basis_end:string,ytd_end:string,revenue_ytd:string,revenue_basis:string,run_rate_monthly:string,projected_revenue:string,
     *     eur:?array{rate:string,rate_table:string,rate_date:string,rate_day:string,rate_estimated:bool,limits:list<array{key:string,limit_eur:string,limit_pln:string,crossed:bool,projected_cross:bool,cross_month:?string}>},
     *     health:?array{revenue_after_social_ytd:string,projected_revenue_after_social:string,bracket_now:int,bracket_projected:int,next_limit_pln:?string,next_cross_month:?string,months_paid:int,health_paid:string,surcharge:string},
     *     reserve:array{period_start:?string,ryczalt_open_period:?string,health_surcharge:string,total:string}}|null
     */
    public function forecast(int $year): ?array
    {
        $today = $this->date->today();
        $current = (int) substr($today, 0, 4);
        if ($year > $current) {
            throw new \InvalidArgumentException(sprintf('Rok %d jeszcze się nie zaczął.', $year));
        }
        $basis = $year < $current ? 12 : (int) substr($today, 5, 2) - 1;
        if ($basis === 0) {
            return null;
        }
        $yearStart = sprintf('%04d-01-01', $year);
        $ytdEnd = min($today, sprintf('%04d-12-31', $year));
        $basisEnd = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $basis)))->modify('last day of this month')->format('Y-m-d');
        $revenueMonthly = $this->monthly('SELECT extract(month FROM revenue_date)::int AS m, sum(amount_pln) AS amount FROM revenue_entry WHERE environment=? AND revenue_date BETWEEN ? AND ? GROUP BY 1', $yearStart, $basisEnd);
        $cumulative = self::cumulative($revenueMonthly, $basis);
        $ytd = BigDecimal::zero();
        foreach ($this->tax->revenueByRate($yearStart, $ytdEnd) as $amount) {
            $ytd = $ytd->plus($amount);
        }
        $projected = BigDecimal::max($ytd, BigDecimal::of($cumulative[11]));
        $health = $this->health($year, $basis, $yearStart, $ytdEnd, $basisEnd, $revenueMonthly);
        $reserve = $this->openPeriodAdvance($year, $current, $today);
        $surcharge = $health['surcharge'] ?? '0.00';
        return [
            'year' => $year,'basis_months' => $basis,'basis_end' => $basisEnd,'ytd_end' => $ytdEnd,'revenue_ytd' => (string) $ytd->toScale(2),'revenue_basis' => $cumulative[$basis - 1] ?? throw new \LogicException('Brak miesiąca podstawy.'),
            'run_rate_monthly' => self::runRate($revenueMonthly, $basis),'projected_revenue' => (string) $projected->toScale(2),
            'eur' => $this->eur($year, $cumulative, $ytd, $projected, $ytdEnd),'health' => $health,
            'reserve' => ['period_start' => $reserve[0],'ryczalt_open_period' => $reserve[1],'health_surcharge' => $surcharge,
                'total' => (string) BigDecimal::of($reserve[1] ?? '0')->plus($surcharge)->toScale(2)],
        ];
    }

    /**
     * Średni przychód miesiąca z miesięcy podstawy, do grosza.
     *
     * @param array<int,string> $monthly kwota per numer miesiąca 1..12; brak klucza to zero
     */
    public static function runRate(array $monthly, int $basisMonths): string
    {
        if ($basisMonths < 1 || $basisMonths > 12) {
            throw new \InvalidArgumentException('Podstawa prognozy to od 1 do 12 miesięcy.');
        }
        $sum = BigDecimal::zero();
        for ($month = 1; $month <= $basisMonths; $month++) {
            $sum = $sum->plus($monthly[$month] ?? '0');
        }
        return (string) $sum->dividedBy($basisMonths, 2, RoundingMode::HALF_UP);
    }

    /**
     * Wartości narastająco na koniec każdego miesiąca roku: rzeczywiste do miesiąca `$basisMonths`, potem suma podstawy
     * plus średnia miesiąca razy liczba miesięcy po podstawie.
     *
     * @param array<int,string> $monthly kwota per numer miesiąca 1..12; brak klucza to zero
     * @return list<string> 12 kwot do grosza
     */
    public static function cumulative(array $monthly, int $basisMonths): array
    {
        $rate = BigDecimal::of(self::runRate($monthly, $basisMonths));
        $running = BigDecimal::zero();
        $result = [];
        for ($month = 1; $month <= 12; $month++) {
            $running = $running->plus($month <= $basisMonths ? ($monthly[$month] ?? '0') : $rate);
            $result[] = (string) $running->toScale(2, RoundingMode::HALF_UP);
        }
        return $result;
    }

    /**
     * Pierwszy miesiąc (1..12), w którym wartość narastająco przekracza limit; null, gdy w roku nie przekracza.
     *
     * @param list<string> $cumulative
     */
    public static function crossMonth(array $cumulative, string $limit): ?int
    {
        foreach ($cumulative as $index => $value) {
            if (BigDecimal::of($value)->isGreaterThan($limit)) {
                return $index + 1;
            }
        }
        return null;
    }

    /**
     * Limity w euro po kursie progów (art. 4 ust. 2): limit w złotych do grosza wobec przychodu rzeczywistego i prognozy.
     *
     * @param list<string> $cumulative
     * @return array{rate:string,rate_table:string,rate_date:string,rate_day:string,rate_estimated:bool,limits:list<array{key:string,limit_eur:string,limit_pln:string,crossed:bool,projected_cross:bool,cross_month:?string}>}|null
     */
    private function eur(int $year, array $cumulative, BigDecimal $ytd, BigDecimal $projected, string $ytdEnd): ?array
    {
        try {
            $threshold = $this->tax->eurThresholdRate($year);
        } catch (FxRateUnavailable) {
            return null;
        }
        $rate = $threshold['rate'];
        $values = $this->settings->value(SettingKeys::RYCZALT_ELIGIBILITY, sprintf('%04d-12-31', $year));
        $limits = [];
        foreach (self::EUR_LIMITS as $field => $key) {
            $eur = is_array($values) && is_string($values[$field] ?? null) ? $values[$field] : null;
            if ($eur === null) {
                continue;
            }
            $pln = BigDecimal::of($eur)->multipliedBy($rate->mid)->toScale(2, RoundingMode::HALF_UP);
            $crossed = $ytd->isGreaterThan($pln);
            // Przekroczenie w trwającym miesiącu nie ma jeszcze miesiąca w podstawie, więc to miesiąc dnia dzisiejszego.
            $month = self::crossMonth($cumulative, (string) $pln) ?? ($crossed ? (int) substr($ytdEnd, 5, 2) : null);
            $limits[] = ['key' => $key,'limit_eur' => $eur,'limit_pln' => (string) $pln,'crossed' => $crossed,'projected_cross' => $projected->isGreaterThan($pln),
                'cross_month' => $month === null ? null : sprintf('%04d-%02d', $year, $month)];
        }
        return ['rate' => $rate->mid,'rate_table' => $rate->tableNo,'rate_date' => $rate->effectiveDate,'rate_day' => $threshold['day'],'rate_estimated' => $threshold['estimated'],'limits' => $limits];
    }

    /**
     * Próg zdrowotnej z przychodu po składkach społecznych (art. 81 ust. 2g) teraz i w prognozie oraz dopłata za miesiące
     * już zapłacone, gdyby rok skończył się progiem z prognozy (roczne rozliczenie, docs/tax-rules.md).
     *
     * @param array<int,string> $revenueMonthly
     * @return array{revenue_after_social_ytd:string,projected_revenue_after_social:string,bracket_now:int,bracket_projected:int,next_limit_pln:?string,next_cross_month:?string,months_paid:int,health_paid:string,surcharge:string}|null
     */
    private function health(int $year, int $basis, string $yearStart, string $ytdEnd, string $basisEnd, array $revenueMonthly): ?array
    {
        $parameters = $this->tax->zusParameters(substr($ytdEnd, 0, 7) . '-01');
        if ($parameters === null) {
            return null;
        }
        $brackets = $parameters['health_brackets'];
        $social = $this->monthly('SELECT extract(month FROM paid_on)::int AS m, sum(social_pln) AS amount FROM zus_payment WHERE environment=? AND paid_on BETWEEN ? AND ? GROUP BY 1', $yearStart, $basisEnd);
        $net = [];
        for ($month = 1; $month <= $basis; $month++) {
            $net[$month] = (string) BigDecimal::of($revenueMonthly[$month] ?? '0')->minus($social[$month] ?? '0');
        }
        $cumulative = self::cumulative($net, $basis);
        $now = $this->tax->revenueAfterSocial($yearStart, $ytdEnd);
        $projected = BigDecimal::max(BigDecimal::of($now), BigDecimal::of($cumulative[11]));
        $bracketNow = HealthContribution::bracket($brackets, $now);
        $bracketProjected = HealthContribution::bracket($brackets, (string) $projected);
        $next = $brackets[$bracketNow - 1]['revenue_up_to_pln'];
        $nextMonth = $next === null ? null : self::crossMonth($cumulative, $next);
        $paid = $this->db->fetchAssociative('SELECT count(*) AS months, coalesce(sum(health_pln),0) AS health FROM zus_payment WHERE environment=? AND month BETWEEN ? AND ?', [$this->configuration->environment(), $yearStart, sprintf('%04d-12-01', $year)]);
        $months = is_array($paid) && is_int($paid['months']) ? $paid['months'] : 0;
        $healthPaid = BigDecimal::of(is_array($paid) && (is_string($paid['health']) || is_int($paid['health'])) ? (string) $paid['health'] : '0');
        $surcharge = BigDecimal::of($brackets[$bracketProjected - 1]['amount_pln'])->multipliedBy($months)->minus($healthPaid);
        return ['revenue_after_social_ytd' => $now,'projected_revenue_after_social' => (string) $projected->toScale(2),'bracket_now' => $bracketNow,'bracket_projected' => $bracketProjected,
            'next_limit_pln' => $next,'next_cross_month' => $nextMonth === null ? null : sprintf('%04d-%02d', $year, $nextMonth),'months_paid' => $months,
            'health_paid' => (string) $healthPaid->toScale(2),'surcharge' => (string) ($surcharge->isPositive() ? $surcharge : BigDecimal::zero())->toScale(2)];
    }

    /**
     * Ryczałt narosły w trwającym okresie zaliczki (wzór zaliczki na przychodzie do dziś); tylko w bieżącym roku.
     *
     * @return array{0:?string,1:?string} początek okresu i kwota w złotych
     */
    private function openPeriodAdvance(int $year, int $current, string $today): array
    {
        if ($year !== $current) {
            return [null, null];
        }
        $quarterly = $this->settings->value(SettingKeys::ADVANCE_FREQUENCY, sprintf('%04d-01-01', $year)) === 'quarterly';
        $month = (int) substr($today, 5, 2);
        $start = sprintf('%04d-%02d-01', $year, $quarterly ? intdiv($month - 1, 3) * 3 + 1 : $month);
        $advance = $this->tax->advance($start, $quarterly ? 'quarterly' : 'monthly')['result']->advance;
        return [$start, (string) BigDecimal::of($advance)->toScale(2)];
    }

    /** @return array<int,string> kwota per numer miesiąca */
    private function monthly(string $sql, string $from, string $to): array
    {
        $result = [];
        foreach ($this->db->fetchAllAssociative($sql, [$this->configuration->environment(), $from, $to]) as $row) {
            $month = $row['m'];
            $amount = $row['amount'];
            if (!is_int($month) || !(is_string($amount) || is_int($amount))) {
                throw new \LogicException('Niepoprawna suma miesiąca.');
            }
            $result[$month] = (string) $amount;
        }
        return $result;
    }
}
