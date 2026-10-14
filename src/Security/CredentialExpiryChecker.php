<?php

declare(strict_types=1);

namespace App\Security;

use App\Audit\AuditLog;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Zadanie `credentials.check_expiry` (docs/security.md, „Stany poświadczeń”): rodzaje statyczne przechodzą
 * w `expiring` na 30 dni przed `valid_until` i w `expired` po tej chwili (wpis `credential.expired`). Rodzaje rotujące nie zmieniają stanu.
 */
final readonly class CredentialExpiryChecker
{
    public const STATIC_KINDS = ['ksef_token_read','ksef_token_write','ksef_auth_cert','ksef_offline_cert','auth_data','dummy_token'];
    public const WARNING_DAYS = 30;

    public function __construct(private Connection $db, private ClockInterface $clock, private AuditLog $audit) {}

    /** @return array{expiring:int,expired:int} */
    public function run(): array
    {
        $now = $this->clock->now();
        $result = ['expiring' => 0,'expired' => 0];
        $this->db->transactional(function () use ($now, &$result): void {
            $rows = $this->db->fetchAllAssociative("SELECT id,environment,kind,status,valid_until FROM credential_state WHERE kind IN (?) AND status IN ('valid','expiring') AND valid_until IS NOT NULL FOR UPDATE", [self::STATIC_KINDS], [\Doctrine\DBAL\ArrayParameterType::STRING]);
            foreach ($rows as $row) {
                if (!is_string($row['id']) || !is_string($row['valid_until']) || !is_string($row['environment']) || !is_string($row['kind'])) {
                    throw new \LogicException('Niepoprawny wiersz credential_state.');
                }
                $until = new \DateTimeImmutable($row['valid_until']);
                $status = $until <= $now ? 'expired' : ($until <= $now->modify('+' . self::WARNING_DAYS . ' days') ? 'expiring' : 'valid');
                if ($status === $row['status'] || $status === 'valid') {
                    continue;
                }
                $this->db->update('credential_state', ['status' => $status,'updated_at' => $now->format('Y-m-d\TH:i:s.uP')], ['id' => $row['id']]);
                if ($status === 'expired') {
                    $this->audit->append('credential.expired', ['kind' => $row['kind'],'valid_until' => $until->format('c')], $row['environment'], 'credential_state', $row['id']);
                }
                $result[$status]++;
            }
        });
        return $result;
    }
}
