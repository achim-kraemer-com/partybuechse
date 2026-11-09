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

    public function __construct(
        private readonly string $username,
        private readonly string $credentialsFingerprint = '',
    ) {
    }

    /**
     * The value the remember-me cookie is signed over (security.firewalls.main.remember_me.signature_properties).
     *
     * Symfony signs the user's "password" property by default, and HostUser has none: the host
     * password belongs to the environment, not to the user object. The PropertyAccessor therefore
     * threw NoSuchPropertyException while building the cookie, inside the LoginSuccessEvent and
     * thus after the login had already succeeded - every single login answered with a 500.
     *
     * A fingerprint keeps the credential itself out of the session file and still does the job of
     * the signature: change HOST_PASSWORD(_HASH) and every cookie handed out before stops working.
     */
    public function getCredentialsFingerprint(): string
    {
        return $this->credentialsFingerprint;
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
