<?php

declare(strict_types=1);

namespace App\Rate;

use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

final readonly class RateCounter
{
    /** @param array<string,int> $dailyLimits */
    public function __construct(private Connection $connection, private ClockInterface $clock, private BusinessDate $dates, private array $dailyLimits) {}
    public function consume(string $environment, string $connector, string $bucket, ?\DateTimeImmutable $windowStart = null): RateCounterResult
    {
        $windowStart ??= $this->dates->midnight();
        return $this->connection->transactional(function () use ($environment, $connector, $bucket, $windowStart): RateCounterResult {
            $this->connection->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$environment . '.' . $connector . '.' . $bucket]);
            $blocked = $this->connection->fetchOne('SELECT max(blocked_until) FROM rate_counter WHERE environment=? AND connector=? AND bucket=?', [$environment,$connector,$bucket]);
            if (is_string($blocked) && new \DateTimeImmutable($blocked) > $this->clock->now()) {
                throw new RateLimitExceededException(new \DateTimeImmutable($blocked));
            }
            $limit = $this->dailyLimits[$bucket] ?? null;
            $count = $this->connection->fetchOne('SELECT count FROM rate_counter WHERE environment=? AND connector=? AND bucket=? AND window_start=?', [$environment,$connector,$bucket,$windowStart->format('c')]);
            if ($limit !== null && is_numeric($count) && (int) $count >= $limit) {
                throw new RateLimitExceededException($this->dates->nextMidnight());
            }
            $count = $this->connection->fetchOne('INSERT INTO rate_counter (id,environment,connector,bucket,window_start,count) VALUES (?,?,?,?,?,1) ON CONFLICT (environment,connector,bucket,window_start) DO UPDATE SET count=rate_counter.count+1 RETURNING count', [UuidV7::generate($this->clock->now()),$environment,$connector,$bucket,$windowStart->format('c')]);
            if (!is_numeric($count)) {
                throw new \RuntimeException('Niepoprawny licznik.');
            }
            return new RateCounterResult((int) $count, null);
        });
    }
    /**
     * Odczyt bez zużycia: chwila, do której grupa jest zablokowana (`blocked_until` albo północ po wyczerpanym limicie
     * dobowym), albo NULL, gdy wywołanie może ruszyć. Warunek „wysyłka” bramki sprawdza go przed pierwszym wywołaniem.
     */
    public function exhausted(string $environment, string $connector, string $bucket): ?\DateTimeImmutable
    {
        $blocked = $this->connection->fetchOne('SELECT max(blocked_until) FROM rate_counter WHERE environment=? AND connector=? AND bucket=?', [$environment,$connector,$bucket]);
        if (is_string($blocked) && new \DateTimeImmutable($blocked) > $this->clock->now()) {
            return new \DateTimeImmutable($blocked);
        }
        $limit = $this->dailyLimits[$bucket] ?? null;
        $count = $this->connection->fetchOne('SELECT count FROM rate_counter WHERE environment=? AND connector=? AND bucket=? AND window_start=?', [$environment,$connector,$bucket,$this->dates->midnight()->format('c')]);
        return $limit !== null && is_numeric($count) && (int) $count >= $limit ? $this->dates->nextMidnight() : null;
    }
    public function blockUntil(string $environment, string $connector, string $bucket, \DateTimeImmutable $windowStart, \DateTimeImmutable $until): void
    {
        $this->connection->transactional(function () use ($environment, $connector, $bucket, $windowStart, $until): void {
            $this->connection->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$environment . '.' . $connector . '.' . $bucket]);
            $this->connection->executeStatement('INSERT INTO rate_counter (id,environment,connector,bucket,window_start,count,blocked_until) VALUES (?,?,?,?,?,0,?) ON CONFLICT (environment,connector,bucket,window_start) DO UPDATE SET blocked_until=GREATEST(rate_counter.blocked_until,EXCLUDED.blocked_until)', [UuidV7::generate($this->clock->now()),$environment,$connector,$bucket,$windowStart->format('c'),$until->format('c')]);
        });
    }
}
