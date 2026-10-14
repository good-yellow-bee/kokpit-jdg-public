<?php

declare(strict_types=1);

namespace App\Approval;

use App\Audit\AuditLog;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Dom przejść `approval.status` w kodzie: tabela „Stany zatwierdzenia” z docs/approval-gate.md. Każde przejście to
 * warunkowy `UPDATE … WHERE status = z` i dokładnie jeden wpis w `audit_log`; para spoza tabeli kończy się
 * wyjątkiem bez zmiany stanu i bez wpisu.
 */
final readonly class ApprovalTransitions
{
    public const STATUSES = ['pending', 'approved', 'dispatching', 'submitted', 'accepted', 'rejected', 'unknown', 'expired', 'superseded', 'cancelled'];
    public const LIVE = ['pending', 'approved', 'dispatching', 'submitted', 'unknown'];
    public const FINAL = ['accepted', 'rejected', 'expired', 'superseded', 'cancelled'];

    /** @var list<array{string,string,string,string}> z, do, zdarzenie, wpis `audit_log` */
    public const TABLE = [
        ['pending', 'approved', 'approve', 'approval.approved'],
        ['pending', 'superseded', 'supersede', 'approval.superseded'],
        ['approved', 'superseded', 'supersede', 'approval.superseded'],
        ['pending', 'expired', 'expire', 'approval.expired'],
        ['approved', 'expired', 'expire', 'approval.expired'],
        ['pending', 'cancelled', 'cancel', 'approval.cancelled'],
        ['approved', 'cancelled', 'cancel', 'approval.cancelled'],
        ['approved', 'cancelled', 'hash_mismatch', 'dispatch.hash_mismatch'],
        ['approved', 'dispatching', 'start', 'dispatch.started'],
        ['approved', 'accepted', 'copied', 'draft.executed'],
        ['approved', 'unknown', 'mark_unknown', 'approval.marked_unknown'],
        ['dispatching', 'submitted', 'submitted', 'dispatch.submitted'],
        ['dispatching', 'rejected', 'rejected', 'dispatch.rejected'],
        ['dispatching', 'accepted', 'accepted', 'dispatch.accepted'],
        ['dispatching', 'unknown', 'unknown', 'dispatch.unknown'],
        ['dispatching', 'approved', 'deferred', 'dispatch.deferred'],
        ['submitted', 'accepted', 'accepted', 'dispatch.accepted'],
        ['submitted', 'rejected', 'rejected', 'dispatch.rejected'],
        ['submitted', 'unknown', 'unknown', 'dispatch.unknown'],
        ['unknown', 'accepted', 'resolve', 'reconcile.resolved'],
        ['unknown', 'rejected', 'resolve', 'reconcile.resolved'],
        ['unknown', 'cancelled', 'cancel', 'approval.cancelled'],
        ['unknown', 'superseded', 'retry', 'approval.retry_requested'],
    ];
    private const TIMESTAMP = 'Y-m-d\TH:i:s.uP';

    public function __construct(private Connection $db, private AuditLog $audit, private ClockInterface $clock, private ApprovalSubjects $subjects) {}

    /** Wpis `audit_log` przejścia albo wyjątek dla pary (z, do, zdarzenie) spoza tabeli. */
    public static function action(string $from, string $to, string $event): string
    {
        foreach (self::TABLE as [$tableFrom, $tableTo, $tableEvent, $action]) {
            if ($tableFrom === $from && $tableTo === $to && $tableEvent === $event) {
                return $action;
            }
        }
        throw new InvalidTransitionException(sprintf('Niedozwolone przejście zatwierdzenia %s → %s.', $from, $to));
    }

    public static function allowed(string $from, string $to): bool
    {
        return array_any(self::TABLE, static fn(array $row): bool => $row[0] === $from && $row[1] === $to);
    }

    /**
     * Wiersz `approval` zablokowany `FOR UPDATE`; wywołujący jest w transakcji.
     *
     * @return array<string,mixed>
     */
    public function lock(string $id): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM approval WHERE id=? FOR UPDATE', [$id]);
        if ($row === false) {
            throw new \DomainException('Brak zatwierdzenia.');
        }
        return $row;
    }

    /**
     * Przejście z bieżącego stanu wiersza. Pola `$fields` zapisują się razem ze stanem.
     *
     * @param array<string,mixed> $row
     * @param array<string,scalar|null> $fields
     * @param array<string,mixed> $details
     */
    public function apply(array $row, string $to, string $event, array $fields = [], array $details = [], ?string $actor = null): void
    {
        $from = self::string($row, 'status');
        $action = self::action($from, $to, $event);
        $id = self::string($row, 'id');
        $this->db->transactional(function () use ($row, $from, $to, $action, $id, $fields, $details, $actor): void {
            $changed = $this->db->executeStatement(
                sprintf('UPDATE approval SET status=?%s WHERE id=? AND status=?', implode('', array_map(static fn(string $column): string => sprintf(', %s=?', $column), array_keys($fields)))),
                [$to, ...array_values($fields), $id, $from],
            );
            if ($changed !== 1) {
                throw new InvalidTransitionException('Stan zatwierdzenia zmienił się w międzyczasie.');
            }
            $this->audit->append($action, ['from' => $from, 'to' => $to] + $details, self::string($row, 'environment'), self::string($row, 'subject_type'), self::string($row, 'subject_id'), $actor, $id, self::string($row, 'payload_sha256'), is_string($row['connector'] ?? null) ? $row['connector'] : null);
            $this->subjects->get(self::string($row, 'subject_type'))?->onStatus(self::string($row, 'subject_id'), $to, is_string($fields['cancel_reason'] ?? null) ? $fields['cancel_reason'] : null);
        });
    }

    /**
     * Wpis `approval.requested` nowego wiersza (przejście „– → pending”).
     *
     * @param array<string,mixed> $details
     */
    public function audit(string $action, string $id, array $details, ?string $actor): void
    {
        $row = $this->db->fetchAssociative('SELECT * FROM approval WHERE id=?', [$id]);
        if ($row === false || $action !== 'approval.requested' || $row['status'] !== 'pending') {
            throw new InvalidTransitionException('Wpis prośby dotyczy tylko nowego zatwierdzenia.');
        }
        $this->audit->append($action, ['to' => 'pending'] + $details, self::string($row, 'environment'), self::string($row, 'subject_type'), self::string($row, 'subject_id'), $actor, $id, self::string($row, 'payload_sha256'), is_string($row['connector'] ?? null) ? $row['connector'] : null);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,string>
     */
    public static function refs(array $row): array
    {
        $refs = json_decode(self::string($row, 'external_refs'), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($refs)) {
            throw new \LogicException('Niepoprawne approval.external_refs.');
        }
        $result = [];
        foreach ($refs as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * @param list<mixed> $values kolumna `id` z zapytania
     * @return list<string>
     */
    public static function ids(array $values): array
    {
        return array_map(static fn(mixed $value): string => is_string($value) ? $value : throw new \LogicException('Niepoprawny identyfikator zatwierdzenia.'), $values);
    }

    public function now(): string
    {
        return $this->clock->now()->format(self::TIMESTAMP);
    }

    /** @param array<string,mixed> $row */
    public static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new \LogicException(sprintf('Niepoprawne pole approval.%s.', $key));
        }
        return $value;
    }
}
