<?php

declare(strict_types=1);

namespace App\Approval;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Statusy i rekoncyliacja wyłącznie odczytem (docs/approval-gate.md, „Statusy i rekoncyliacja”): `submitted` →
 * wynik końcowy albo `unknown` po oknie odpytywania, stare `dispatching` → `unknown`, `unknown` → wynik albo bez zmian.
 * Nigdy nie woła metody zapisującej.
 */
final readonly class SubmissionReconciler
{
    /** Awaria workera: `dispatching` starsze niż tyle minut przechodzi do `unknown`. */
    public const STALE_DISPATCHING_MINUTES = 10;
    /** Push `connector_failure` dla `unknown` starszego niż tyle minut. */
    public const UNKNOWN_ALERT_MINUTES = 60;

    /** @param array<string,string> $pollWindow okno odpytywania `submitted` per konektor, ISO 8601 */
    public function __construct(private Connection $db, private ApprovalTransitions $transitions, private ApprovalConnectors $connectors, private ApprovalNotifications $notifications, private ClockInterface $clock, private array $pollWindow) {}

    /** Zadanie `approval.poll_status`. @return int liczba zmienionych zatwierdzeń */
    public function poll(): int
    {
        $changed = 0;
        foreach ($this->db->fetchAllAssociative("SELECT id,connector,submitted_at FROM approval WHERE status='submitted' ORDER BY submitted_at, id") as $row) {
            $id = ApprovalTransitions::string($row, 'id');
            $reader = $this->connectors->reader(ApprovalTransitions::string($row, 'connector'));
            if ($reader === null) {
                continue;
            }
            $current = $this->db->fetchAssociative('SELECT * FROM approval WHERE id=?', [$id]);
            if ($current === false) {
                continue;
            }
            $result = $reader->status($id, ApprovalTransitions::refs($current), ApprovalTransitions::string($current, 'payload_sha256'));
            $window = $this->pollWindow[ApprovalTransitions::string($row, 'connector')] ?? 'PT1H';
            $late = (new \DateTimeImmutable(ApprovalTransitions::string($row, 'submitted_at')))->add(new \DateInterval($window)) <= $this->clock->now();
            $to = match (true) {
                in_array($result->outcome, ['accepted', 'rejected'], true) => $result->outcome,
                $late => 'unknown',
                default => null,
            };
            if ($to !== null) {
                $changed += $this->move($id, 'submitted', $to, $to, $result, $to === 'unknown' ? ['reason' => 'poll_window'] : []);
            }
        }
        return $changed;
    }

    /** Zadanie `approval.reconcile`. @return int liczba zmienionych zatwierdzeń */
    public function reconcile(): int
    {
        $changed = 0;
        $stale = $this->clock->now()->modify(sprintf('-%d minutes', self::STALE_DISPATCHING_MINUTES))->format('Y-m-d\TH:i:s.uP');
        foreach (ApprovalTransitions::ids($this->db->fetchFirstColumn("SELECT id FROM approval WHERE status='dispatching' AND dispatching_at < ? ORDER BY dispatching_at, id", [$stale])) as $id) {
            $changed += $this->move($id, 'dispatching', 'unknown', 'unknown', SubmissionResult::of('unknown', [], 'worker_lost'), ['reason' => 'worker_lost']);
        }
        foreach ($this->db->fetchAllAssociative("SELECT * FROM approval WHERE status='unknown' ORDER BY id") as $row) {
            $id = ApprovalTransitions::string($row, 'id');
            $reader = is_string($row['connector']) ? $this->connectors->reader($row['connector']) : null;
            $result = $reader?->reconcile($id, ApprovalTransitions::refs($row), ApprovalTransitions::string($row, 'payload_sha256'));
            if ($result !== null && in_array($result->outcome, ['accepted', 'rejected'], true)) {
                $changed += $this->move($id, 'unknown', $result->outcome, 'resolve', $result, []);
                continue;
            }
            if ($this->unknownSince($row) <= $this->clock->now()->modify(sprintf('-%d minutes', self::UNKNOWN_ALERT_MINUTES))) {
                $this->notifications->failure($id, 'unknown');
            }
        }
        return $changed;
    }

    /**
     * Początek stanu `unknown`: ostatni krok wysyłki albo decyzja przy odtworzeniu z backupu.
     *
     * @param array<string,mixed> $row
     */
    public function unknownSince(array $row): \DateTimeImmutable
    {
        $moments = array_filter([$row['submitted_at'] ?? null, $row['dispatching_at'] ?? null, $row['decided_at'] ?? null], is_string(...));
        return $moments === [] ? $this->clock->now() : max(array_map(static fn(string $moment): \DateTimeImmutable => new \DateTimeImmutable($moment), $moments));
    }

    /** @param array<string,mixed> $details */
    private function move(string $id, string $from, string $to, string $event, SubmissionResult $result, array $details): int
    {
        return $this->db->transactional(function () use ($id, $from, $to, $event, $result, $details): int {
            $row = $this->transitions->lock($id);
            if ($row['status'] !== $from) {
                return 0;
            }
            $fields = ['result_code' => $result->resultCode];
            if ($result->externalRefs !== []) {
                $fields['external_refs'] = json_encode(ApprovalTransitions::refs($row) + $result->externalRefs, JSON_THROW_ON_ERROR);
            }
            if (in_array($to, ['accepted', 'rejected'], true)) {
                $fields['finished_at'] = $this->transitions->now();
            }
            $this->transitions->apply($row, $to, $event, $fields, $details + ['result_code' => $result->resultCode]);
            return 1;
        });
    }
}
