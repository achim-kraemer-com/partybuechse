<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Party;
use App\Entity\Suggestion;
use App\Entity\SuggestionStatus;
use App\Repository\SuggestionRepository;
use App\Service\PlaybackSync;
use App\Service\VotingService;
use App\Spotify\PlaybackState;
use App\Spotify\TrackInfo;
use App\Tests\Support\FakeSpotifyApi;
use App\Tests\Support\Fixtures;
use App\Tests\Support\SchemaTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class PlaybackSyncTest extends KernelTestCase
{
    use SchemaTrait;

    private EntityManagerInterface $em;
    private FakeSpotifyApi $spotify;
    private MockClock $clock;
    private PlaybackSync $sync;
    private SuggestionRepository $suggestions;
    private VotingService $voting;
    private Party $party;
    private Suggestion $top;
    private Suggestion $second;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->resetSchema($this->em);

        $this->suggestions = $container->get(SuggestionRepository::class);
        $this->voting = $container->get(VotingService::class);
        $this->spotify = new FakeSpotifyApi();
        $this->clock = new MockClock('2026-11-09 21:00:00');

        $this->sync = new PlaybackSync(
            $this->spotify,
            $this->suggestions,
            $this->em,
            $this->clock,
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
        );

        $now = $this->clock->now();
        $host = Fixtures::host($this->em, $now);
        $this->party = Fixtures::party($this->em, $host, $now);
        $anna = Fixtures::guest($this->em, $this->party, $now, str_repeat('a', 48), 'Anna');
        $ben = Fixtures::guest($this->em, $this->party, $now, str_repeat('b', 48), 'Ben');

        $this->top = $this->voting->suggest($anna, Fixtures::track('1', 'Top Wish'));
        $this->voting->vote($ben, $this->top);
        $this->second = $this->voting->suggest($ben, Fixtures::track('2', 'Second Wish'));
    }

    public function testNoActiveDeviceIsReportedAndNothingIsQueued(): void
    {
        $this->spotify->noActiveDevice = true;

        $result = $this->sync->sync($this->party);

        self::assertSame(Party::SYNC_NO_DEVICE, $result->status);
        self::assertSame(Party::SYNC_NO_DEVICE, $this->party->getSyncStatus());
        self::assertNull($this->party->getNowPlaying());
        self::assertNotContains('queue', $this->spotify->callNames());
        self::assertSame(SuggestionStatus::Open, $this->top->getStatus());
    }

    public function testNothingHappensWhileTheCurrentTrackHasPlentyOfTimeLeft(): void
    {
        $this->spotify->state = $this->playing(Fixtures::track('h', 'Host Song'), progressMs: 10_000, isPlaying: true);

        $result = $this->sync->sync($this->party);

        self::assertSame(Party::SYNC_OK, $result->status);
        self::assertSame([], $result->actions);
        self::assertSame('Host Song', $this->party->getNowPlaying()['track']['title']);
        self::assertSame(SuggestionStatus::Open, $this->top->getStatus());
    }

    public function testTopWishIsQueuedShortlyBeforeTheTrackEnds(): void
    {
        $this->spotify->state = $this->playing(Fixtures::track('h', 'Host Song'), progressMs: 185_000, isPlaying: true);

        $result = $this->sync->sync($this->party);

        self::assertSame(Party::SYNC_OK, $result->status);
        self::assertContains(['queue', $this->top->getSpotifyUri()], $this->spotify->calls);
        self::assertSame(SuggestionStatus::Queued, $this->top->getStatus());
        self::assertSame(SuggestionStatus::Open, $this->second->getStatus());

        // a second run within the same window must not queue the next wish as well
        $this->sync->sync($this->party);
        self::assertSame(1, \count(array_filter($this->spotify->calls, static fn (array $c) => 'queue' === $c[0])));
        self::assertSame(SuggestionStatus::Open, $this->second->getStatus());
    }

    /**
     * Steht der Wunsch schon in Spotifys Warteschlange, unsere Liste weiss es aber nicht – so
     * geschehen, als addToQueue an einer unlesbaren Antwort scheiterte, obwohl Spotify den Titel
     * angenommen hatte –, dann muss der Abgleich das nachtragen. Bleibt der Wunsch offen,
     * belegt er bei jedem Poll erneut die oberste Stelle und die Liste bewegt sich nie weiter.
     */
    public function testAWishAlreadyInSpotifysQueueIsRecordedInsteadOfBlockingTheList(): void
    {
        $this->spotify->queue = [$this->top->getSpotifyUri()];
        $this->spotify->state = $this->playing(Fixtures::track('h', 'Host Song'), progressMs: 185_000, isPlaying: true);

        $result = $this->sync->sync($this->party);

        self::assertSame(Party::SYNC_OK, $result->status);
        self::assertSame(SuggestionStatus::Queued, $this->top->getStatus());
        self::assertNotContains(['queue', $this->top->getSpotifyUri()], $this->spotify->calls, 'Nicht ein zweites Mal einreihen.');

        // Ist der Wunsch gelaufen, muss der naechste an die Reihe kommen.
        $this->spotify->queue = [];
        $this->spotify->state = $this->playing($this->top->toTrackInfo(), progressMs: 1_000, isPlaying: true);
        $this->sync->sync($this->party);
        self::assertSame(SuggestionStatus::Played, $this->top->getStatus());

        $this->spotify->state = $this->playing(Fixtures::track('h', 'Host Song'), progressMs: 185_000, isPlaying: true);
        $this->sync->sync($this->party);
        self::assertSame(SuggestionStatus::Queued, $this->second->getStatus());
    }

    public function testQueuedWishBecomesPlayedWhenItComesOn(): void
    {
        $this->top->markQueued($this->clock->now());
        $this->em->flush();

        $this->clock->sleep(30);
        $this->spotify->state = $this->playing($this->top->toTrackInfo(), progressMs: 2_000, isPlaying: true);

        $result = $this->sync->sync($this->party);

        self::assertSame(SuggestionStatus::Played, $this->top->getStatus());
        self::assertSame('Anna', $this->party->getNowPlaying()['wishedBy']);
        self::assertSame([], array_filter($this->spotify->calls, static fn (array $c) => 'queue' === $c[0]));
        self::assertStringContainsString('Top Wish', (string) $result->message);
    }

    public function testIdlePlayerStartsTheTopWishImmediately(): void
    {
        $this->spotify->state = new PlaybackState('dev', 'Küche', false, 0, null, $this->clock->now());

        $this->sync->sync($this->party);

        self::assertContains(['play', [$this->top->getSpotifyUri()], null], $this->spotify->calls);
        self::assertSame(SuggestionStatus::Queued, $this->top->getStatus());
    }

    public function testPausedPlayerIsLeftAlone(): void
    {
        $this->spotify->state = $this->playing(Fixtures::track('h', 'Host Song'), progressMs: 60_000, isPlaying: false);

        $this->sync->sync($this->party);

        self::assertNotContains('play', $this->spotify->callNames());
        self::assertNotContains('queue', $this->spotify->callNames());
        self::assertSame(SuggestionStatus::Open, $this->top->getStatus());
    }

    public function testWishThatVanishedFromSpotifysQueueIsConsideredConsumed(): void
    {
        $this->top->markQueued($this->clock->now());
        $this->em->flush();

        // inside the grace period: still queued
        $this->clock->sleep(30);
        $this->spotify->state = $this->playing(Fixtures::track('h', 'Host Song'), progressMs: 30_000, isPlaying: true);
        $this->sync->sync($this->party);
        self::assertSame(SuggestionStatus::Queued, $this->top->getStatus());

        // after the grace period and not in the queue anymore: consumed, next wish may follow
        $this->clock->sleep(PlaybackSync::QUEUE_GRACE_SECONDS);
        $this->sync->sync($this->party);
        self::assertSame(SuggestionStatus::Played, $this->top->getStatus());
    }

    public function testWishPlayedManuallyByTheHostCountsAsPlayed(): void
    {
        $this->spotify->state = $this->playing($this->second->toTrackInfo(), progressMs: 5_000, isPlaying: true);

        $this->sync->sync($this->party);

        self::assertSame(SuggestionStatus::Played, $this->second->getStatus());
        self::assertSame(SuggestionStatus::Open, $this->top->getStatus());
        self::assertSame('Ben', $this->party->getNowPlaying()['wishedBy']);
    }

    public function testFallbackPlaylistStartsWhenIdleAndNothingIsWished(): void
    {
        $this->top->markPlayed($this->clock->now());
        $this->second->markPlayed($this->clock->now());
        $this->party->setFallbackPlaylistUri('spotify:playlist:'.str_repeat('p', 22));
        $this->em->flush();

        $this->spotify->state = new PlaybackState('dev', 'Küche', false, 0, null, $this->clock->now());

        $result = $this->sync->sync($this->party);

        self::assertContains(['play', [], 'spotify:playlist:'.str_repeat('p', 22)], $this->spotify->calls);
        self::assertStringContainsString('Fallback', (string) $result->message);
    }

    public function testSyncIfDueIsThrottled(): void
    {
        $this->spotify->state = $this->playing(Fixtures::track('h', 'Host Song'), progressMs: 10_000, isPlaying: true);

        self::assertNotNull($this->sync->syncIfDue($this->party));
        self::assertNull($this->sync->syncIfDue($this->party));

        $this->clock->sleep(PlaybackSync::MIN_INTERVAL_SECONDS);
        self::assertNotNull($this->sync->syncIfDue($this->party));
    }

    private function playing(TrackInfo $track, int $progressMs, bool $isPlaying): PlaybackState
    {
        return new PlaybackState('dev', 'Wohnzimmer', $isPlaying, $progressMs, $track, $this->clock->now());
    }
}
