<?php

declare(strict_types=1);

namespace App\Health;

use App\Config\ConnectorConfiguration;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\UX\Chartjs\Model\Chart;

/**
 * Zdrowie konektorów w panelu (D-85): podsumowanie `connector_call` per konektor, wywołania i błędy per dzień,
 * aktywne blokady `rate_counter` i wiek `sync_cursor`. Tylko odczyt; bez ścieżek żądań, bo mogą nieść NIP albo rachunek.
 */
final readonly class ConnectorHealth
{
    /** Okresy do wyboru w panelu, w dniach kalendarzowych Europe/Warsaw (dziś i dni wstecz). */
    public const PERIODS = [1, 7, 30];
    public const DEFAULT_PERIOD = 7;
    public const MAX_ERRORS = 50;
    /** Błąd wywołania: odpowiedź 4xx/5xx albo kod błędu konektora (sieć, limit, allowlista). */
    private const ERROR = '(c.http_status >= 400 OR c.error_code IS NOT NULL)';
    private const RATE_LIMITED = "(c.http_status = 429 OR c.error_code = 'rate_limited')";

    public function __construct(private Connection $db, private ClockInterface $clock, private ConnectorConfiguration $configuration, private BusinessDate $date) {}

    /** @return array{days: int, since: string, connectors: list<array<string, mixed>>, daily: list<array{day: string, calls: int, errors: int}>, errors: list<array<string, mixed>>, blocks: list<array<string, mixed>>, cursors: list<array<string, mixed>>} */
    public function summary(int $days): array
    {
        if (!in_array($days, self::PERIODS, true)) {
            throw new \InvalidArgumentException('Okres spoza listy ConnectorHealth::PERIODS.');
        }
        $environment = $this->configuration->environment();
        $since = $this->date->midnight()->modify(sprintf('-%d days', $days - 1));
        $sinceUtc = $since->format('c');
        $connectors = $this->db->fetchAllAssociative(
            'SELECT c.connector, count(*) AS calls, count(*) FILTER (WHERE ' . self::ERROR . ') AS errors, count(*) FILTER (WHERE ' . self::RATE_LIMITED . ') AS rate_limited,
                round(avg(c.duration_ms)) AS avg_ms, percentile_disc(0.95) WITHIN GROUP (ORDER BY c.duration_ms) AS p95_ms, max(c.started_at) AS last_at,
                max(c.started_at) FILTER (WHERE ' . self::ERROR . ') AS last_error_at
             FROM connector_call c WHERE c.environment=? AND c.started_at >= ? GROUP BY c.connector ORDER BY c.connector',
            [$environment, $sinceUtc],
        );
        $daily = [];
        $rows = $this->db->fetchAllAssociative(
            "SELECT to_char(d, 'YYYY-MM-DD') AS day, count(c.id) AS calls, count(c.id) FILTER (WHERE " . self::ERROR . ") AS errors
             FROM generate_series(?::date, ?::date, interval '1 day') d
             LEFT JOIN connector_call c ON c.environment=? AND c.started_at >= ? AND (c.started_at AT TIME ZONE 'Europe/Warsaw')::date = d::date
             GROUP BY d ORDER BY d",
            [$since->format('Y-m-d'), $this->date->today(), $environment, $sinceUtc],
        );
        foreach ($rows as $row) {
            $daily[] = ['day' => self::text($row['day']), 'calls' => self::count($row['calls']), 'errors' => self::count($row['errors'])];
        }
        return [
            'days' => $days,
            'since' => $since->format('Y-m-d'),
            'connectors' => $connectors,
            'daily' => $daily,
            'errors' => $this->db->fetchAllAssociative(
                'SELECT c.started_at, c.connector, c.operation, c.host, c.http_status, c.error_code, c.retry_after_s, c.duration_ms
                 FROM connector_call c WHERE c.environment=? AND c.started_at >= ? AND ' . self::ERROR . ' ORDER BY c.started_at DESC, c.sequence DESC LIMIT ' . self::MAX_ERRORS,
                [$environment, $sinceUtc],
            ),
            'blocks' => $this->db->fetchAllAssociative('SELECT connector, bucket, window_start, count, blocked_until FROM rate_counter WHERE environment=? AND blocked_until > ? ORDER BY blocked_until', [$environment, $this->clock->now()->format('c')]),
            'cursors' => $this->db->fetchAllAssociative('SELECT connector, stream, last_run_at, last_success_at, backfill_done_at FROM sync_cursor WHERE environment=? ORDER BY connector, stream', [$environment]),
        ];
    }

    /** @param list<array{day: string, calls: int, errors: int}> $daily */
    public static function chart(array $daily): Chart
    {
        $chart = new Chart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => array_column($daily, 'day'),
            'datasets' => [
                ['label' => 'Wywołania bez błędu', 'data' => array_map(static fn(array $day): int => $day['calls'] - $day['errors'], $daily)],
                ['label' => 'Błędy', 'data' => array_column($daily, 'errors')],
            ],
        ]);
        $chart->setOptions(['scales' => ['x' => ['stacked' => true], 'y' => ['stacked' => true, 'beginAtZero' => true]]]);
        return $chart;
    }

    private static function count(mixed $value): int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : 0;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
