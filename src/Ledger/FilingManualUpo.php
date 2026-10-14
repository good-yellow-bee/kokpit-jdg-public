<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Approval\ApprovalGate;
use App\Audit\AuditLog;
use App\Passkey\StepUp;
use App\Storage\FileStore;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Wgranie UPO złożenia zrobionego poza kokpitem dla istniejącego `filing` (docs/approval-gate.md, „Przedmioty
 * zatwierdzeń”): ze step-upem; żywe zatwierdzenie `pending`, `approved` albo nierozstrzygnięte `unknown` (np. po JPK 411)
 * przechodzi do `cancelled` z `manual_filing`, złożenie kończy w `accepted` jako `manual`, UPO trafia do `FileStore`,
 * a wpis `filing.manual_recorded` do `audit_log`. Przy `dispatching` i `submitted` bramka blokuje wgranie.
 */
final readonly class FilingManualUpo
{
    private const UPO_TYPES = ['application/pdf', 'application/xml', 'text/xml'];

    public function __construct(private Connection $db, private ApprovalGate $gate, private StepUp $stepUp, private FileStore $files, private AuditLog $audit, private ClockInterface $clock) {}

    public function record(string $filingId, string $userId, string $upo, string $mediaType): void
    {
        $credential = $this->stepUp->require();
        if ($upo === '' || !in_array($mediaType, self::UPO_TYPES, true)) {
            throw new \InvalidArgumentException('Wgraj UPO jako PDF albo XML.');
        }
        $this->db->transactional(function () use ($filingId, $userId, $upo, $mediaType, $credential): void {
            $filing = $this->db->fetchAssociative('SELECT environment, type, period_start, status FROM filing WHERE id=? FOR UPDATE', [$filingId]);
            if ($filing === false || !is_string($filing['environment']) || !is_string($filing['type']) || !is_string($filing['period_start'])) {
                throw new \DomainException('Brak złożenia.');
            }
            if (in_array($filing['status'], ['accepted', 'superseded'], true)) {
                throw new \DomainException('Złożenie jest już przyjęte.');
            }
            $this->gate->recordManualFiling($filingId, $userId);
            $year = (int) $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y');
            $this->files->put($upo, $filing['environment'], 'upo', $mediaType, 'filing', $filingId, $year);
            $sha = hash('sha256', $upo);
            $this->audit->append('filing.manual_recorded', ['type' => $filing['type'], 'period_start' => $filing['period_start'], 'upo_sha256' => $sha, 'step_up_credential_id' => $credential], $filing['environment'], 'filing', $filingId, $userId, payloadSha256: $sha);
        });
    }
}
