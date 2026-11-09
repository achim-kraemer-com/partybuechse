<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\HostCredentials;
use PHPUnit\Framework\TestCase;

final class HostCredentialsTest extends TestCase
{
    public function testTheHashWinsOverThePlainPassword(): void
    {
        $credentials = new HostCredentials('achim', 'klartext', password_hash('gehasht', \PASSWORD_BCRYPT));

        self::assertTrue($credentials->verifyPassword('gehasht'));
        self::assertFalse($credentials->verifyPassword('klartext'));
    }

    public function testThePlainPasswordIsUsedWhenNoHashIsConfigured(): void
    {
        $credentials = new HostCredentials('achim', 'klartext', null);

        self::assertTrue($credentials->verifyPassword('klartext'));
        self::assertFalse($credentials->verifyPassword('falsch'));
    }

    public function testNothingIsAcceptedWithoutConfiguredCredentials(): void
    {
        $credentials = new HostCredentials(null, null, null);

        self::assertFalse($credentials->isConfigured());
        self::assertFalse($credentials->verifyPassword(''));
        self::assertSame('', $credentials->fingerprint());
    }

    /**
     * Das Remember-me-Cookie wird über diesen Fingerabdruck signiert. Bleibt er beim Wechsel des
     * Passworts gleich, bliebe ein ausgestelltes Cookie 30 Tage lang gültig – deshalb dieser Test.
     */
    public function testTheFingerprintChangesWithThePassword(): void
    {
        $before = (new HostCredentials('achim', 'altes-passwort', null))->fingerprint();
        $after = (new HostCredentials('achim', 'neues-passwort', null))->fingerprint();

        self::assertNotSame($before, $after);
        self::assertSame($before, (new HostCredentials('achim', 'altes-passwort', null))->fingerprint());
        self::assertStringNotContainsString('altes-passwort', $before, 'Der Fingerabdruck darf das Passwort nicht enthalten.');
    }
}
