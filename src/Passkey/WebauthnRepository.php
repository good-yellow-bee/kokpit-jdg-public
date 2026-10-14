<?php

declare(strict_types=1);

namespace App\Passkey;

use App\Audit\AuditLog;
use Doctrine\DBAL\Connection;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Webauthn\Bundle\Repository\CanGenerateUserEntity;
use Webauthn\Bundle\Repository\CanRegisterUserEntity;
use Webauthn\Bundle\Repository\CanSaveCredentialRecord;
use Webauthn\Bundle\Repository\CredentialRecordRepositoryInterface;
use Webauthn\Bundle\Repository\PublicKeyCredentialUserEntityRepositoryInterface;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialUserEntity;

/** Database-backed repository used by the bundle's attestation and assertion validators. */
final readonly class WebauthnRepository implements CredentialRecordRepositoryInterface, PublicKeyCredentialUserEntityRepositoryInterface, CanSaveCredentialRecord, CanGenerateUserEntity, CanRegisterUserEntity
{
    public function __construct(private Connection $connection, private NormalizerInterface&DenormalizerInterface $serializer, private AuditLog $auditLog, private \Symfony\Component\Clock\ClockInterface $clock, private \Symfony\Component\HttpFoundation\RequestStack $requests) {}

    public function findOneByUsername(string $username): ?PublicKeyCredentialUserEntity
    {
        $row = $this->connection->fetchAssociative('SELECT id, username, display_name FROM app_user WHERE username = :username AND disabled_at IS NULL', ['username' => $username]);

        if ($row === false || !is_string($row['username'] ?? null) || !is_string($row['id'] ?? null) || !is_string($row['display_name'] ?? null)) {
            return null;
        }
        return PublicKeyCredentialUserEntity::create($row['username'], $row['id'], $row['display_name']);
    }

    public function findOneByUserHandle(string $userHandle): ?PublicKeyCredentialUserEntity
    {
        $row = $this->connection->fetchAssociative('SELECT id, username, display_name FROM app_user WHERE id = :id AND disabled_at IS NULL', ['id' => $userHandle]);

        if ($row === false || !is_string($row['username'] ?? null) || !is_string($row['id'] ?? null) || !is_string($row['display_name'] ?? null)) {
            return null;
        }
        return PublicKeyCredentialUserEntity::create($row['username'], $row['id'], $row['display_name']);
    }

    public function generateUserEntity(?string $username, ?string $displayName): PublicKeyCredentialUserEntity
    {
        $username !== null && $displayName !== null || throw new \InvalidArgumentException('Passkey requires a user.');
        $user = $this->findOneByUsername($username);
        $user !== null || throw new \InvalidArgumentException('Unknown passkey user.');

        return $user;
    }

    public function saveUserEntity(PublicKeyCredentialUserEntity $userEntity): void
    {
        // app_user is created by the SSH-only enrolment command, never by a public ceremony endpoint.
    }

    /** @return array<CredentialRecord> */
    public function findAllForUserEntity(PublicKeyCredentialUserEntity $user): array
    {
        $rows = $this->connection->fetchFirstColumn('SELECT credential_source FROM webauthn_credential WHERE app_user_id = :id AND revoked_at IS NULL', ['id' => $user->id]);

        return array_map(function (mixed $source): CredentialRecord {
            is_string($source) || throw new \UnexpectedValueException('Brak zapisu passkeya.');
            return $this->serializer->denormalize(json_decode($source, true, flags: JSON_THROW_ON_ERROR), CredentialRecord::class);
        }, $rows);
    }

    public function findOneByCredentialId(string $credentialId): ?CredentialRecord
    {
        $source = $this->connection->fetchOne("SELECT credential_source FROM webauthn_credential WHERE credential_id = decode(:id, 'hex') AND revoked_at IS NULL", ['id' => bin2hex($credentialId)]);

        if (!is_string($source)) {
            return null;
        }
        return $this->serializer->denormalize(json_decode($source, true, flags: JSON_THROW_ON_ERROR), CredentialRecord::class);
    }

    public function saveCredentialRecord(CredentialRecord $record): void
    {
        $normalized = $this->serializer->normalize($record);
        $now = $this->clock->now()->format('c');
        $source = json_encode($normalized, JSON_THROW_ON_ERROR);
        $this->connection->transactional(function () use ($record, $now, $source): void {
            $existing = $this->connection->fetchAssociative("SELECT id,revoked_at,sign_count,encode(public_key,'hex') AS public_key FROM webauthn_credential WHERE credential_id=decode(?,'hex') FOR UPDATE", [bin2hex($record->publicKeyCredentialId)]);
            if ($existing !== false) {
                if ($existing['revoked_at'] !== null || $existing['public_key'] !== bin2hex($record->credentialPublicKey)) {
                    throw new \LogicException('Passkey jest unieważniony.');
                }
                if (!is_numeric($existing['sign_count']) || ((int) $existing['sign_count'] > 0 && $record->counter <= (int) $existing['sign_count'])) {
                    throw new \LogicException('Licznik passkeya nie wzrósł.');
                }
                $this->connection->executeStatement('UPDATE webauthn_credential SET sign_count=?,credential_source=?,last_used_at=?,updated_at=? WHERE id=?', [$record->counter,$source,$now,$now,$existing['id']]);
                return;
            }
            $hash = $this->requests->getCurrentRequest()?->getSession()->get('passkey.enrollment_hash');
            if (!is_string($hash)) {
                throw new \LogicException('Brak sesji rejestracji.');
            }
            $user = $this->connection->fetchAssociative('SELECT id FROM app_user WHERE id=? AND enrollment_token_hash=? AND enrollment_used_at IS NULL AND enrollment_expires_at>? AND disabled_at IS NULL FOR UPDATE', [$record->userHandle,$hash,$now]);
            if ($user === false || !is_string($user['id'])) {
                throw new \LogicException('Link rejestracji wygasł.');
            }
            $this->connection->executeStatement("INSERT INTO webauthn_credential (id,app_user_id,credential_id,public_key,sign_count,transports,aaguid,label,credential_source,created_at,updated_at) VALUES (?,?,decode(?,'hex'),decode(?,'hex'),?,?::jsonb,?,?,?,?,?)", [\Symfony\Component\Uid\UuidV7::generate($this->clock->now()),$user['id'],bin2hex($record->publicKeyCredentialId),bin2hex($record->credentialPublicKey),$record->counter,json_encode($record->transports, JSON_THROW_ON_ERROR),$record->aaguid->toRfc4122(),'Passkey',$source,$now,$now]);
            $this->connection->executeStatement('UPDATE app_user SET enrollment_used_at=?,enrollment_token_hash=NULL,updated_at=? WHERE id=?', [$now,$now,$user['id']]);
            $this->auditLog->append('auth.passkey_enrolled', [], targetType: 'app_user', targetId: $user['id']);
        });
    }
}
