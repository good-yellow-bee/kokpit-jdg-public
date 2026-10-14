<?php

namespace App\Audit;

use App\Security\Redactor;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

final readonly class AuditLog
{
    public function __construct(private Connection $db, private ClockInterface $clock, private Redactor $redactor) {}
    /** @param array<string,mixed> $details */
    public function append(string $action, array $details = [], ?string $environment = null, ?string $targetType = null, ?string $targetId = null, ?string $actorUserId = null, ?string $approvalId = null, ?string $payloadSha256 = null, ?string $connector = null): string
    {
        return $this->db->transactional(function () use ($action, $details, $environment, $targetType, $targetId, $actorUserId, $approvalId, $payloadSha256, $connector): string {
            $this->db->executeQuery('SELECT pg_advisory_xact_lock(7071401)');
            $previous = $this->db->fetchOne('SELECT hash FROM audit_log ORDER BY sequence DESC LIMIT 1');
            $row = ['id' => UuidV7::generate($this->clock->now()),'occurred_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'),'actor_user_id' => $actorUserId,'action' => $action,'target_type' => $targetType,'target_id' => $targetId,'environment' => $environment,'connector' => $connector,'approval_id' => $approvalId,'payload_sha256' => $payloadSha256,'details' => $this->redactor->redact($details),'prev_hash' => is_string($previous) ? $previous : str_repeat('0', 64)];
            $row['hash'] = self::hash($row);
            $row['details'] = json_encode($row['details'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->db->insert('audit_log', $row);
            return $row['id'];
        });
    }
    public function verify(): bool
    {
        $previous = str_repeat('0', 64);
        foreach ($this->db->fetchAllAssociative('SELECT * FROM audit_log ORDER BY sequence') as $row) {
            if (!is_string($row['occurred_at']) || !is_string($row['details'])) {
                return false;
            }
            $row['occurred_at'] = (new \DateTimeImmutable($row['occurred_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
            $row['details'] = json_decode($row['details'], true, 512, JSON_THROW_ON_ERROR);
            if ($row['prev_hash'] !== $previous || $row['hash'] !== self::hash($row)) {
                return false;
            }
            $previous = $row['hash'];
        }
        return true;
    }
    /** @param array<string,mixed> $row */
    private static function hash(array $row): string
    {
        $previous = $row['prev_hash'];
        unset($row['hash'],$row['prev_hash'],$row['sequence']);
        if (!is_string($previous)) {
            throw new \LogicException('Niepoprawny hash.');
        }
        return hash('sha256', $previous . json_encode(self::canonical($row), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }
    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonical($item);
        }
        return $value;
    }
}
