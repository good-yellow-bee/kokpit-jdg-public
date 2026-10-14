<?php

declare(strict_types=1);

namespace App\Health;

use App\Scheduler\HeartbeatStore;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

final readonly class HealthChecker
{
    /** @param array<string,int> $queueMaxAge */
    public function __construct(private Connection $connection, private ClockInterface $clock, private string $appEnvironment, private HeartbeatStore $heartbeatStore, private HealthResources $resources, private array $queueMaxAge, private int $heartbeatMaxAge, private int $backupMaxAge, private int $diskMinFreePercent, private int $tlsMinValidDays) {}
    /** @return list<HealthCheckResult> */
    public function check(): array
    {
        try {
            $this->connection->fetchOne('SELECT 1');
        } catch (\Doctrine\DBAL\Exception) {
            return [new HealthCheckResult('database', false)];
        }
        $now = $this->clock->now();
        try {
            $checks = [new HealthCheckResult('database', true)];
            foreach ($this->queueMaxAge as $transport => $minutes) {
                $oldest = $this->connection->fetchOne('SELECT min(created_at) FROM messenger_messages WHERE queue_name=?', [$transport]);
                $checks[] = new HealthCheckResult($transport . '_queue', $oldest === null || (is_string($oldest) && new \DateTimeImmutable($oldest) >= $now->modify('-' . $minutes . ' minutes')));
            }
            $checks[] = new HealthCheckResult('scheduler_heartbeat', $this->heartbeatStore->isFresh($now, $this->heartbeatMaxAge));
            $checks[] = new HealthCheckResult('failed_messages', is_numeric($count = $this->connection->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name='failed'")) && (int) $count === 0);
            $checks[] = new HealthCheckResult('credentials', is_numeric($count = $this->connection->fetchOne("SELECT count(*) FROM credential_state WHERE status='expired'")) && (int) $count === 0);
            // Zatwierdzenie `unknown` starsze niż 1 h; początek stanu jak w `SubmissionReconciler::unknownSince`.
            $checks[] = new HealthCheckResult('approval_unknown', is_numeric($count = $this->connection->fetchOne("SELECT count(*) FROM approval WHERE status='unknown' AND GREATEST(submitted_at, dispatching_at, decided_at) < ?", [$now->modify('-1 hour')->format('c')])) && (int) $count === 0);
            $free = $this->resources->diskFreePercent();
            $checks[] = new HealthCheckResult('disk_space', $free !== null && $free >= $this->diskMinFreePercent);
            $checks[] = new HealthCheckResult('connectors', true);
            if ($this->appEnvironment === 'prod') {
                $modified = $this->resources->backupModifiedAt();
                $expires = $this->resources->certificateValidUntil();
                $checks[] = new HealthCheckResult('backup', $modified !== null && $modified >= $now->getTimestamp() - $this->backupMaxAge);
                $checks[] = new HealthCheckResult('tls_certificate', $expires !== null && $expires >= $now->getTimestamp() + $this->tlsMinValidDays * 86400);
            }
            return $checks;
        } catch (\Doctrine\DBAL\Exception) {
            return [new HealthCheckResult('database', false)];
        }
    }
}
