<?php

declare(strict_types=1);

namespace App\Counterparty;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Push `connector_failure` rejestrów: VIES bez wyniku po 24 h ponowień albo kod spoza listy „Awarii”. Treść bez
 * identyfikatorów kontrahenta; szczegóły są w panelu. Jeden problem daje jeden wiersz (`dedupe_key`).
 */
final readonly class RegistryAlerts
{
    public const LINK = '/';

    public function __construct(private Connection $db, private ClockInterface $clock) {}

    public function connectorFailure(string $environment, string $connector, string $reason, string $subjectKey): void
    {
        $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
        $this->db->executeStatement(
            'INSERT INTO notification (id,channel,category,dedupe_key,body,deep_link,scheduled_for,attempts,created_at) VALUES (?,?,?,?,?,?,?,0,?) ON CONFLICT (dedupe_key) DO NOTHING',
            [(string) UuidV7::generate($this->clock->now()), 'push', 'connector_failure', sprintf('%s:%s:%s:%s:push', $environment, $connector, $reason, $subjectKey), sprintf('connector_failure: %s, %s; %s', $connector, $reason, self::LINK), self::LINK, $now, $now],
        );
    }
}
