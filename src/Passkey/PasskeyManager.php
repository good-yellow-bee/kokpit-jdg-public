<?php

declare(strict_types=1);

namespace App\Passkey;

use App\Audit\AuditLog;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/** Drugi passkey i unieważnienie w panelu (docs/security.md, „Passkey”); obie akcje tylko po step-upie. */
final readonly class PasskeyManager
{
    public function __construct(private Connection $db, private PasskeyEnrollmentService $enrollment, private StepUpVerifier $stepUp, private AuditLog $audit, private ClockInterface $clock) {}

    /** @return list<array{id:string,label:string,created_at:string,last_used_at:?string}> */
    public function active(string $userId): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT id,label,created_at,last_used_at FROM webauthn_credential WHERE app_user_id=? AND revoked_at IS NULL ORDER BY created_at', [$userId]);
        $result = [];
        foreach ($rows as $row) {
            if (!is_string($row['id']) || !is_string($row['label']) || !is_string($row['created_at'])) {
                throw new \LogicException('Niepoprawny wiersz passkeya.');
            }
            $result[] = ['id' => $row['id'],'label' => $row['label'],'created_at' => $row['created_at'],'last_used_at' => is_string($row['last_used_at']) ? $row['last_used_at'] : null];
        }
        return $result;
    }

    /** Ostrzeżenie w panelu: jeden passkey, także po resecie do rejestracji drugiego. */
    public function warning(string $userId): bool
    {
        return count($this->active($userId)) < 2;
    }

    /** Wydaje jednorazowy link rejestracji kolejnego passkeya po świeżym step-upie. */
    public function enrolmentToken(AppUser $user): string
    {
        $this->stepUp->require();
        $row = $this->db->fetchAssociative('SELECT username,display_name FROM app_user WHERE id=?', [$user->id()]);
        if ($row === false || !is_string($row['username']) || !is_string($row['display_name'])) {
            throw new \DomainException('Brak użytkownika.');
        }
        return $this->enrollment->issue($row['username'], $row['display_name'], false, true)['token'];
    }

    /** Unieważnia passkey po step-upie; ostatniego aktywnego passkeya nie unieważnisz (odzyskanie: `app:passkey:reset`). */
    public function revoke(AppUser $user, string $credentialId): void
    {
        $stepUp = $this->stepUp->require();
        $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
        $this->db->transactional(function () use ($user, $credentialId, $stepUp, $now): void {
            $active = $this->db->fetchFirstColumn('SELECT id FROM webauthn_credential WHERE app_user_id=? AND revoked_at IS NULL FOR UPDATE', [$user->id()]);
            if (!in_array($credentialId, $active, true)) {
                throw new \DomainException('Brak aktywnego passkeya o tym identyfikatorze.');
            }
            if (count($active) < 2) {
                throw new \DomainException('To ostatni aktywny passkey. Odzyskanie dostępu: app:passkey:reset przez SSH.');
            }
            $this->db->update('webauthn_credential', ['revoked_at' => $now,'updated_at' => $now], ['id' => $credentialId]);
            $this->audit->append('auth.passkey_revoked', ['credential_id' => $credentialId,'step_up_credential_id' => $stepUp], targetType: 'app_user', targetId: $user->id());
        });
    }
}
