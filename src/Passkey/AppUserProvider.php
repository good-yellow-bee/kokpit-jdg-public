<?php

declare(strict_types=1);

namespace App\Passkey;

use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/** @implements UserProviderInterface<AppUser> */
final readonly class AppUserProvider implements UserProviderInterface
{
    public function __construct(private Connection $connection) {}

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->connection->fetchAssociative('SELECT id, username FROM app_user WHERE username = :username AND disabled_at IS NULL', ['username' => $identifier]);
        if ($user === false) {
            throw new UserNotFoundException('Nie znaleziono użytkownika.');
        }

        is_string($user['id']) && is_string($user['username']) && $user['username'] !== '' || throw new UserNotFoundException('Nieprawidłowy użytkownik.');
        return new AppUser($user['id'], $user['username']);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof AppUser) {
            throw new UnsupportedUserException();
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === AppUser::class;
    }
}
