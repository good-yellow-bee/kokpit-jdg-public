<?php

declare(strict_types=1);

namespace App\Passkey;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

final readonly class PasskeyEnrollmentService
{
    public function __construct(private Connection $connection, private ClockInterface $clock) {}

    /**
     * `$additional`: drugi passkey z panelu po step-upie (F0b); bez niego link wydaje tylko SSH przy braku passkeya.
     *
     * @return array{user_id: string, token: string, expires_at: \DateTimeImmutable}
     */
    public function issue(string $username, string $displayName, bool $revokeCredentials = false, bool $additional = false): array
    {
        $user = $this->connection->fetchAssociative('SELECT id FROM app_user WHERE username = :username', ['username' => $username]);
        $userId = is_array($user) && is_string($user['id'] ?? null) ? $user['id'] : UuidV7::generate($this->clock->now());
        $now = $this->clock->now();
        $expires = $now->modify('+15 minutes');
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $token);

        $this->connection->transactional(function () use ($user, $userId, $username, $displayName, $now, $expires, $hash, $revokeCredentials, $additional): void {
            if ($user === false || $user === []) {
                $this->connection->insert('app_user', [
                    'id' => $userId,
                    'username' => $username,
                    'display_name' => $displayName,
                    'created_at' => $now->format('Y-m-d H:i:sP'),
                    'updated_at' => $now->format('Y-m-d H:i:sP'),
                ]);
            }
            if (!$revokeCredentials && !$additional && $this->connection->fetchOne('SELECT id FROM webauthn_credential WHERE app_user_id=? AND revoked_at IS NULL', [$userId]) !== false) {
                throw new \LogicException('Jeden passkey jest już zarejestrowany. Użyj resetu przez SSH.');
            }
            if ($revokeCredentials) {
                $this->connection->executeStatement('UPDATE webauthn_credential SET revoked_at = :now, updated_at = :now WHERE app_user_id = :id AND revoked_at IS NULL', ['now' => $now->format('Y-m-d H:i:sP'), 'id' => $userId]);
            }
            $this->connection->update('app_user', [
                'enrollment_token_hash' => $hash,
                'enrollment_expires_at' => $expires->format('Y-m-d H:i:sP'),
                'enrollment_used_at' => null,
                'updated_at' => $now->format('Y-m-d H:i:sP'),
            ], ['id' => $userId]);
        });

        return ['user_id' => $userId, 'token' => $token, 'expires_at' => $expires];
    }

    /** @return array{id: string, username: string, display_name: string}|null */
    public function consumeableUser(string $token): ?array
    {
        $user = $this->connection->fetchAssociative(
            'SELECT id, username, display_name FROM app_user WHERE enrollment_token_hash = :hash AND enrollment_used_at IS NULL AND enrollment_expires_at > :now AND disabled_at IS NULL',
            ['hash' => hash('sha256', $token), 'now' => $this->clock->now()->format('Y-m-d H:i:sP')],
        );

        if ($user === false || !is_string($user['id'] ?? null) || !is_string($user['username'] ?? null) || !is_string($user['display_name'] ?? null)) {
            return null;
        }

        return ['id' => $user['id'], 'username' => $user['username'], 'display_name' => $user['display_name']];
    }

}
