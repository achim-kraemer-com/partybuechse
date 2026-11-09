<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Party;
use App\Repository\PartyRepository;
use App\Security\HostUser;
use App\Tests\Support\Fixtures;
use App\Tests\Support\SchemaTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pro Spotify-Konto steuert immer nur eine Party die Musik.
 */
final class HostPartyTest extends WebTestCase
{
    use SchemaTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->resetSchema($this->em);
        $this->now = new \DateTimeImmutable('2026-11-09 21:00:00');
        $this->client->loginUser(new HostUser('achim'));
    }

    public function testANewPartyEndsTheRunningOne(): void
    {
        $host = Fixtures::host($this->em, $this->now);
        Fixtures::party($this->em, $host, $this->now, code: 'OLD11', name: 'Alte Party');

        $this->client->request('GET', '/host');
        $this->client->submitForm('Party anlegen', ['name' => 'Neue Party']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-info', 'Alte Party');
        self::assertFalse($this->party('OLD11')->isActive(), 'Die vorige Party muss beendet sein.');
        self::assertSame(Party::SYNC_ENDED, $this->party('OLD11')->getSyncStatus());
    }

    public function testAnOldPartyCannotBeReopenedWhileAnotherIsRunning(): void
    {
        $host = Fixtures::host($this->em, $this->now);
        $old = Fixtures::party($this->em, $host, $this->now, code: 'OLD11', name: 'Alte Party');
        Fixtures::party($this->em, $host, $this->now->modify('+1 hour'), code: 'NEW11', name: 'Laufende Party');
        $old->end($this->now);
        $this->em->flush();

        $this->client->request('GET', '/host/party/OLD11');
        $this->client->submitForm('Party wieder öffnen');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-error', 'Laufende Party');
        self::assertFalse($this->party('OLD11')->isActive(), 'Die alte Party darf nicht nebenher aufgehen.');
    }

    public function testTheOnlyPartyCanBeReopened(): void
    {
        $host = Fixtures::host($this->em, $this->now);
        $party = Fixtures::party($this->em, $host, $this->now, code: 'OLD11', name: 'Alte Party');
        $party->end($this->now);
        $this->em->flush();

        $this->client->request('GET', '/host/party/OLD11');
        $this->client->submitForm('Party wieder öffnen');
        $this->client->followRedirect();

        self::assertTrue($this->party('OLD11')->isActive());
    }

    /** Frisch aus der Datenbank: der Kernel wird pro Anfrage neu gestartet, Objekte veralten. */
    private function party(string $code): Party
    {
        return static::getContainer()->get(PartyRepository::class)->findOneByCode($code)
            ?? self::fail(sprintf('Party "%s" nicht gefunden.', $code));
    }
}
