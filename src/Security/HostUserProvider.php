<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * @implements UserProviderInterface<HostUser>
 */
final class HostUserProvider implements UserProviderInterface
{
    public function __construct(private readonly HostCredentials $credentials)
    {
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof HostUser) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return HostUser::class === $class;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        if (!$this->credentials->matchesUsername($identifier)) {
            $e = new UserNotFoundException();
            $e->setUserIdentifier($identifier);
            throw $e;
        }

        return new HostUser($this->credentials->getUsername(), $this->credentials->fingerprint());
    }
}
