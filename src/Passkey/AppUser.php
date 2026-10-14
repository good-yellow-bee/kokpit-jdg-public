<?php

declare(strict_types=1);

namespace App\Passkey;

use Symfony\Component\Security\Core\User\UserInterface;

final readonly class AppUser implements UserInterface
{
    /** @param non-empty-string $username */
    public function __construct(private string $id, private string $username) {}

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void {}

    public function id(): string
    {
        return $this->id;
    }
}
