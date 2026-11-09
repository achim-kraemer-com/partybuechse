<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\GuestResolver;
use App\Tests\Support\Fixtures;
use App\Tests\Support\SchemaTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SmokeTest extends WebTestCase
{
    use SchemaTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->resetSchema($this->em);
    }

    public function testHomePageRenders(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Deine Gäste');
        self::assertSelectorExists('form[action="/join"]');
    }

    public function testUnknownPartyCodeIsA404Page(): void
    {
        $this->client->request('GET', '/p/NOPE1');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'gibt es nicht');
    }

    public function testHostAreaRedirectsToLoginWithoutSession(): void
    {
        $this->client->request('GET', '/host');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testSpotifyConnectIsNotReachableAnonymously(): void
    {
        $this->client->request('GET', '/spotify/login');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testGuestPageSetsCookieAndServesState(): void
    {
        $now = new \DateTimeImmutable();
        $host = Fixtures::host($this->em, $now);
        $party = Fixtures::party($this->em, $host, $now);

        $this->client->request('GET', '/p/'.$party->getCode());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.brand-sub', 'Testparty');

        $cookie = $this->client->getCookieJar()->get(GuestResolver::COOKIE);
        self::assertNotNull($cookie, 'guest cookie should be issued');

        $this->client->request('GET', '/api/p/'.$party->getCode().'/state');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($data['ok']);
        self::assertSame('TEST1', $data['party']['code']);
        self::assertSame(3, $data['me']['votesLeft']);
        self::assertSame(1, $data['guestCount']);
    }

    public function testQrCodeIsAnSvg(): void
    {
        $now = new \DateTimeImmutable();
        $host = Fixtures::host($this->em, $now);
        $party = Fixtures::party($this->em, $host, $now);

        $this->client->request('GET', '/p/'.$party->getCode().'/qr.svg');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/svg+xml');
        self::assertStringContainsString('<svg', (string) $this->client->getResponse()->getContent());
    }

    public function testDisplayNeedsTheRightKey(): void
    {
        $now = new \DateTimeImmutable();
        $host = Fixtures::host($this->em, $now);
        $party = Fixtures::party($this->em, $host, $now);

        $this->client->request('GET', '/display/'.$party->getCode().'/'.str_repeat('f', 24));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/display/'.$party->getCode().'/'.$party->getDisplayKey());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.display-ranking h2', 'Wunschliste');
    }
}
