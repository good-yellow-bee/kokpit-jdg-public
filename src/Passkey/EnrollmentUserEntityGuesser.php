<?php

declare(strict_types=1);

namespace App\Passkey;

use Symfony\Component\HttpFoundation\Request;
use Webauthn\Bundle\Exception\MissingUserEntityException;
use Webauthn\Bundle\Security\Guesser\UserEntityGuesser;
use Webauthn\PublicKeyCredentialUserEntity;

/** Limits the anonymous attestation ceremony to a previously verified SSH link. */
final readonly class EnrollmentUserEntityGuesser implements UserEntityGuesser
{
    public function __construct(private WebauthnRepository $repository, private \Doctrine\DBAL\Connection $connection, private \Symfony\Component\Clock\ClockInterface $clock) {}

    public function findUserEntity(Request $request): PublicKeyCredentialUserEntity
    {
        $id = $request->getSession()->get('passkey.enrollment_user_id');
        if (!is_string($id)) {
            throw MissingUserEntityException::create('Brak aktywnego linku rejestracji.');
        }
        $hash = $request->getSession()->get('passkey.enrollment_hash');
        $kind = is_string($hash) ? $this->connection->fetchOne("SELECT COALESCE(enrollment_kind,'passkey') FROM app_user WHERE id=? AND enrollment_token_hash=? AND enrollment_used_at IS NULL AND enrollment_expires_at>?", [$id,$hash,$this->clock->now()->format('c')]) : false;
        if (!is_string($kind)) {
            throw MissingUserEntityException::create('Link rejestracji wygasł.');
        }
        // Link na klucz FIDO2 rusza tylko profil `security_key` (cross-platform), link na passkey tylko profil `panel` (D-90).
        if ($request->getPathInfo() !== AuthenticatorKind::from($kind)->enrolPaths()['options']) {
            throw MissingUserEntityException::create('Link rejestracji dotyczy innego rodzaju uwierzytelniacza.');
        }
        $user = $this->repository->findOneByUserHandle($id);
        $user !== null || throw MissingUserEntityException::create('Link rejestracji wygasł.');

        return $user;
    }
}
