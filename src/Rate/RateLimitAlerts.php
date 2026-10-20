<?php

declare(strict_types=1);

namespace App\Rate;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Push `connector_failure` przy każdym 429 (docs/architecture.md, „Liczniki limitów”, reguła „Push”). Treść to konektor,
 * grupa i `blocked_until`, bez danych żądania. Jedna blokada daje jeden wiersz (`dedupe_key` z `blocked_until`).
 * `push` i `mail` pomijamy: ich 429 i tak wstrzymuje wysyłkę powiadomień.
 */
final readonly class RateLimitAlerts
{
    public const LINK = '/';

    public function __construct(private Connection $db, private ClockInterface $clock) {}

    public function rateLimited(string $environment, string $connector, string $bucket, \DateTimeImmutable $blockedUntil): void
    {
        if (in_array($connector, ['push', 'mail'], true)) {
            return;
        }
        $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
        $until = $blockedUntil->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $this->db->executeStatement(
            'INSERT INTO notification (id,channel,category,dedupe_key,body,deep_link,scheduled_for,attempts,created_at) VALUES (?,?,?,?,?,?,?,0,?) ON CONFLICT (dedupe_key) DO NOTHING',
            [UuidV7::generate($this->clock->now()), 'push', 'connector_failure', sprintf('%s:%s:%s:%s:rate_limited:push', $environment, $connector, $bucket, $until), sprintf('connector_failure: %s 429, %s do %s; %s', $connector, $bucket, $until, self::LINK), self::LINK, $now, $now],
        );
    }
}
