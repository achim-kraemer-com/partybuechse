<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Host login credentials from the environment.
 *
 * HOST_USERNAME plus either HOST_PASSWORD_HASH (bcrypt/argon, generate with
 * `php bin/console security:hash-password` or `php -r 'echo password_hash("…", PASSWORD_DEFAULT);'`)
 * or, for quick setups, HOST_PASSWORD in plain text. The hash wins when both are set.
 */
final class HostCredentials
{
    private readonly string $username;
    private readonly string $password;
    private readonly string $passwordHash;

    public function __construct(?string $hostUsername, ?string $hostPassword, ?string $hostPasswordHash)
    {
        $this->username = trim((string) $hostUsername);
        $this->password = (string) $hostPassword;
        $this->passwordHash = trim((string) $hostPasswordHash);
    }

    public function isConfigured(): bool
    {
        return '' !== $this->username && ('' !== $this->passwordHash || '' !== $this->password);
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function matchesUsername(string $identifier): bool
    {
        return '' !== $this->username && hash_equals($this->username, trim($identifier));
    }

    public function verifyPassword(string $plainPassword): bool
    {
        if ('' !== $this->passwordHash) {
            return password_verify($plainPassword, $this->passwordHash);
        }
        if ('' !== $this->password) {
            return hash_equals($this->password, $plainPassword);
        }

        return false;
    }
}
