<?php

declare(strict_types=1);

namespace App\Approval;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Wiersze `notification` bramki („Powiadomienia i eskalacja”); wysyła je `notifications.dispatch`. Treść to kategoria,
 * liczba i link `/approvals`, bez kwot i nazw. Deduplikacja po `approval_id` w `dedupe_key`.
 */
final readonly class ApprovalNotifications
{
    public const LINK = '/approvals';

    public function __construct(private Connection $db, private ClockInterface $clock) {}

    /** Push `approval_pending` przy prośbie systemowej. */
    public function pending(string $approvalId): void
    {
        $this->insert($approvalId, 'approval_pending', 'requested');
    }

    /** Przypomnienie o prośbie czekającej 24 h. */
    public function reminder(string $approvalId): void
    {
        $this->insert($approvalId, 'approval_pending', 'reminder_24h');
    }

    /** Push `connector_failure`: wygasłe `approved`, `hash_mismatch`, `unknown` starsze niż 1 h. */
    public function failure(string $approvalId, string $reason): void
    {
        $this->insert($approvalId, 'connector_failure', $reason);
    }

    private function insert(string $approvalId, string $category, string $label): void
    {
        $now = $this->clock->now();
        $count = $category === 'approval_pending' ? $this->db->fetchOne("SELECT count(*) FROM approval WHERE status='pending'") : 1;
        $this->db->executeStatement(
            'INSERT INTO notification (id,channel,category,approval_id,dedupe_key,body,deep_link,scheduled_for,attempts,created_at) VALUES (?,?,?,?,?,?,?,?,0,?) ON CONFLICT (dedupe_key) DO NOTHING',
            [(string) UuidV7::generate($now), 'push', $category, $approvalId, sprintf('%s:%s:%s:push', $approvalId, $category, $label), sprintf('%s: %d; %s', $category, is_numeric($count) ? max(1, (int) $count) : 1, self::LINK), self::LINK, $now->format('Y-m-d\TH:i:s.uP'), $now->format('Y-m-d\TH:i:s.uP')],
        );
    }
}
