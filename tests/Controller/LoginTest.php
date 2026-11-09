<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Support\SchemaTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Host login with credentials from .env.test (HOST_USERNAME=achim, HOST_PASSWORD=geheim).
 */
final class LoginTest extends WebTestCase
{
    use SchemaTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetSchema(static::getContainer()->get(EntityManagerInterface::class));
    }

    public function testLoginFormRenders(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/login"] input[name="_username"]');
        self::assertSelectorExists('input[name="_csrf_token"]');
    }

    public function testWrongPasswordIsRejected(): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Anmelden', ['_username' => 'achim', '_password' => 'falsch']);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'stimmt nicht');
    }

    public function testUnknownUserIsRejectedWithTheSameMessage(): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Anmelden', ['_username' => 'jemand', '_password' => 'geheim']);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'stimmt nicht');
    }

    public function testSuccessfulLoginOpensTheDashboardAndAsksToConnectSpotify(): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Anmelden', ['_username' => 'achim', '_password' => 'geheim']);

        self::assertResponseRedirects('/host');
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Spotify');
        self::assertSelectorExists('a[href="/spotify/login"]');
    }

    public function testLoginRedirectsBackToTheRequestedPage(): void
    {
        $this->client->request('GET', '/host');
        self::assertResponseRedirects('http://localhost/login');
        $this->client->followRedirect();

        $this->client->submitForm('Anmelden', ['_username' => 'achim', '_password' => 'geheim']);
        self::assertResponseRedirects('http://localhost/host');
    }

    public function testLogoutEndsTheSession(): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Anmelden', ['_username' => 'achim', '_password' => 'geheim']);
        $this->client->followRedirect();

        $this->client->submitForm('Abmelden');
        self::assertResponseRedirects('http://localhost/');

        $this->client->request('GET', '/host');
        self::assertResponseRedirects('http://localhost/login');
    }
}
