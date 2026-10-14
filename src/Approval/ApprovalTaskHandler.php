<?php

declare(strict_types=1);

namespace App\Approval;

use App\Messenger\Message\ApprovalTaskMessage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Zadania bramki na `reads`: `approval.expire`, `approval.poll_status`, `approval.reconcile` (docs/operations.md, „Katalog zadań”). */
final readonly class ApprovalTaskHandler
{
    /** Przypomnienie o prośbie czekającej tyle godzin. */
    public const REMINDER_HOURS = 24;

    public function __construct(private Connection $db, private ApprovalTransitions $transitions, private SubmissionReconciler $reconciler, private ApprovalNotifications $notifications, private ClockInterface $clock) {}

    #[AsMessageHandler]
    public function __invoke(ApprovalTaskMessage $message): void
    {
        match ($message->id) {
            'approval.expire' => $this->expire(),
            'approval.poll_status' => $this->reconciler->poll(),
            'approval.reconcile' => $this->reconciler->reconcile(),
            default => null,
        };
    }

    /**
     * Wygasza `pending` i `approved` po `expires_at` bez nowej prośby, pliku i push `approval_pending` (przegląd S2).
     * Wygasłe `approved` daje push `connector_failure`. Prośba czekająca 24 h dostaje jedno przypomnienie.
     *
     * @return int liczba wygaszonych zatwierdzeń
     */
    public function expire(): int
    {
        $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
        $count = 0;
        foreach (ApprovalTransitions::ids($this->db->fetchFirstColumn("SELECT id FROM approval WHERE status IN ('pending','approved') AND expires_at <= ? ORDER BY expires_at, id", [$now])) as $id) {
            $count += $this->db->transactional(function () use ($id): int {
                $row = $this->transitions->lock($id);
                if (!in_array($row['status'], ['pending', 'approved'], true) || new \DateTimeImmutable(ApprovalTransitions::string($row, 'expires_at')) > $this->clock->now()) {
                    return 0;
                }
                $this->transitions->apply($row, 'expired', 'expire', ['finished_at' => $this->transitions->now()]);
                if ($row['status'] === 'approved') {
                    $this->notifications->failure($id, 'expired');
                }
                return 1;
            });
        }
        $reminder = $this->clock->now()->modify(sprintf('-%d hours', self::REMINDER_HOURS))->format('Y-m-d\TH:i:s.uP');
        foreach (ApprovalTransitions::ids($this->db->fetchFirstColumn("SELECT id FROM approval WHERE status='pending' AND requested_at <= ? ORDER BY id", [$reminder])) as $id) {
            $this->notifications->reminder($id);
        }
        return $count;
    }
}
