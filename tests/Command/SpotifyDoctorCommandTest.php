<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Party;
use App\Repository\SuggestionRepository;
use App\Service\VotingService;
use App\Spotify\PlaybackState;
use App\Spotify\SpotifyApiInterface;
use App\Tests\Support\FakeSpotifyApi;
use App\Tests\Support\Fixtures;
use App\Tests\Support\SchemaTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Die Diagnose für den Fall „Spotify quittiert den Start und spielt nichts“.
 */
final class SpotifyDoctorCommandTest extends KernelTestCase
{
    use SchemaTrait;

    private FakeSpotifyApi $spotify;
    private Party $party;
    private string $wishUri;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        $this->resetSchema($em);

        $this->spotify = new FakeSpotifyApi();
        $container->set(SpotifyApiInterface::class, $this->spotify);

        $now = new \DateTimeImmutable('2026-11-11 21:00:00');
        $host = Fixtures::host($em, $now);
        $this->party = Fixtures::party($em, $host, $now);
        $anna = Fixtures::guest($em, $this->party, $now, str_repeat('a', 48), 'Anna');

        $track = Fixtures::track('1', 'Top Wish');
        $this->wishUri = $track->uri;
        $this->spotify->tracks[$track->uri] = $track;
        $container->get(VotingService::class)->suggest($anna, $track);
        self::assertNotNull($container->get(SuggestionRepository::class)->findTopOpen($this->party));

        $this->spotify->devices = [
            ['id' => 'dev', 'name' => 'Küche', 'type' => 'Speaker', 'is_active' => true, 'is_restricted' => false],
        ];
        $this->spotify->state = new PlaybackState('dev', 'Küche', false, 0, null, $now);
    }

    public function testItNamesTheDevicesAndReadsOnlyWithoutPlay(): void
    {
        $tester = $this->tester();
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $output = $tester->getDisplay();
        self::assertStringContainsString('Küche', $output);
        self::assertStringContainsString('steuerbar', $output);
        self::assertSame([], array_filter($this->spotify->calls, static fn (array $c): bool => 'play' === $c[0]));
    }

    public function testItReportsAStartThatSpotifyOnlyAcknowledges(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute(['--play' => null, '--wait' => '0']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('Der Titel läuft nicht', $tester->getDisplay());
        self::assertContains(['play', [$this->wishUri], null], $this->spotify->calls);
    }

    public function testItReportsAStartThatReallyStarts(): void
    {
        $this->spotify->playbackStarts = true;

        $tester = $this->tester();
        $tester->execute(['--play' => null, '--wait' => '0']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Der Titel läuft', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        return new CommandTester(
            (new Application(self::$kernel))->find('app:spotify:doctor'),
        );
    }
}
