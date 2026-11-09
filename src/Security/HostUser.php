<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The single host account of this installation (credentials come from the environment).
 */
final class HostUser implements UserInterface
{
    public const ROLE = 'ROLE_HOST';

    public function __construct(private readonly string $username)
    {
    }

    public function getRoles(): array
    {
        return [self::ROLE];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }
}
