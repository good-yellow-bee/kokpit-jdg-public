<?php

declare(strict_types=1);

namespace App\Chat;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Część `retention.purge` dla czatu (docs/security.md, „Retencja”): rozmowa znika `chat.retention_months` po `closed_at`
 * razem z wiadomościami (kaskada klucza obcego); szkic zostaje z `draft.chat_session_id` = NULL. Otwarte rozmowy zostają.
 */
final readonly class ChatRetention
{
    public function __construct(private Connection $db, private ClockInterface $clock, private int $retentionMonths)
    {
        if ($retentionMonths < 1) {
            throw new \InvalidArgumentException('chat.retention_months musi być dodatni.');
        }
    }

    public function purge(): int
    {
        $cutoff = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->modify(sprintf('-%d months', $this->retentionMonths));
        return (int) $this->db->executeStatement('DELETE FROM chat_session WHERE closed_at IS NOT NULL AND closed_at < ?', [$cutoff->format('Y-m-d\TH:i:s.uP')]);
    }
}
