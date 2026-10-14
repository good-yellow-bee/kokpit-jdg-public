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
        if (!is_string($hash) || $this->connection->fetchOne('SELECT id FROM app_user WHERE id=? AND enrollment_token_hash=? AND enrollment_used_at IS NULL AND enrollment_expires_at>?', [$id,$hash,$this->clock->now()->format('c')]) === false) {
            throw MissingUserEntityException::create('Link rejestracji wygasł.');
        }
        $user = $this->repository->findOneByUserHandle($id);
        $user !== null || throw MissingUserEntityException::create('Link rejestracji wygasł.');

        return $user;
    }
}
