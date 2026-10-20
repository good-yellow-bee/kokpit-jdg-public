<?php

declare(strict_types=1);

namespace App\Fx;

use App\Calendar\PolishCalendar;
use App\Config\ConnectorConfiguration;
use App\Counterparty\RegistryAlerts;
use App\Time\BusinessDate;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * `getRate(currency, date)`: kurs średni z ostatniej tabeli A NBP opublikowanej przed `date`
 * (docs/modules/rejestry-kursy.md, kroki 1–5). Cache to niezmienne wiersze `fx_rate`. Pusty zakres (krok 5) to błąd
 * i alert `connector_failure`, bez sięgania po starszy kurs.
 */
final readonly class FxRateProvider
{
    public function __construct(private Connection $db, private PolishCalendar $calendar, private BusinessDate $businessDate, private ClockInterface $clock, private NbpRateSource $source, private ?RegistryAlerts $alerts = null, private ?ConnectorConfiguration $configuration = null) {}

    public function getRate(string $currency, string $date): FxRate
    {
        if ($currency === 'PLN' || !preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new \InvalidArgumentException(sprintf('Kurs NBP dotyczy waluty obcej, nie %s.', $currency));
        }
        $today = $this->businessDate->today();
        if ($date > self::shift($today, '+1 day')) {
            throw new \InvalidArgumentException(sprintf('Data kursu %s jest późniejsza niż jutro.', $date));
        }
        $previous = $this->calendar->previousWorkingDay($date);
        $cached = $this->row($currency, $previous);
        if ($cached !== null) {
            return $cached;
        }
        $rows = $this->source->range($currency, self::shift($date, '-10 days'), self::shift($date, '-1 day'));
        $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
        foreach ($rows as $row) {
            $this->db->executeStatement(
                "INSERT INTO fx_rate (id,source,table_no,currency,effective_date,mid,fetched_at,connector_call_id) VALUES (?,'nbp',?,?,?,?,?,?) ON CONFLICT (source,currency,effective_date) DO NOTHING",
                [UuidV7::generate($this->clock->now()), $row['no'], $currency, $row['effectiveDate'], (string) BigDecimal::of($row['mid'])->toScale(6), $now, $row['connectorCallId']],
            );
        }
        if ($previous === $today && $this->row($currency, $today) === null) {
            throw new FxRateUnavailable(sprintf('Tabela A z %s jeszcze nie opublikowana; zadanie czeka na publikację.', $today));
        }
        $latest = $this->db->fetchOne("SELECT max(effective_date) FROM fx_rate WHERE source='nbp' AND currency=? AND effective_date<? AND effective_date>=?", [$currency, $date, self::shift($date, '-10 days')]);
        if (!is_string($latest)) {
            $this->alerts?->connectorFailure($this->configuration?->environment() ?? 'test', 'nbp', 'empty_range', $currency . ':' . $date);
            throw new FxRateUnavailable(sprintf('Brak tabeli A %s w 10 dniach przed %s.', $currency, $date));
        }
        return $this->row($currency, $latest) ?? throw new \LogicException('Kurs zniknął.');
    }

    private function row(string $currency, string $effectiveDate): ?FxRate
    {
        $row = $this->db->fetchAssociative("SELECT id,table_no,effective_date,mid FROM fx_rate WHERE source='nbp' AND currency=? AND effective_date=?", [$currency, $effectiveDate]);
        if ($row === false) {
            return null;
        }
        if (!is_string($row['id']) || !is_string($row['table_no']) || !is_string($row['effective_date']) || !is_string($row['mid'])) {
            throw new \LogicException('Niepoprawny wiersz fx_rate.');
        }
        return new FxRate($row['id'], $currency, $row['table_no'], $row['effective_date'], $row['mid']);
    }

    private static function shift(string $date, string $modifier): string
    {
        return (new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC')))->modify($modifier)->format('Y-m-d');
    }
}
