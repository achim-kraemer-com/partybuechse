<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Party;
use App\Entity\Suggestion;
use App\Entity\SuggestionStatus;
use App\Repository\PartyRepository;
use App\Repository\SuggestionRepository;
use App\Service\PlaybackSync;
use App\Service\VotingService;
use App\Spotify\PlaybackState;
use App\Spotify\SpotifyApiException;
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

/**
 * Der DJ-Modus: Die Partybüchse startet jeden Titel selbst und reiht nichts bei Spotify ein.
 */
final class PlaybackSyncTest extends KernelTestCase
{
    use SchemaTrait;

    private EntityManagerInterface $em;
    private FakeSpotifyApi $spotify;
    private MockClock $clock;
    private PlaybackSync $sync;
    private SuggestionRepository $suggestions;
    private PartyRepository $parties;
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
        $this->parties = $container->get(PartyRepository::class);
        $this->voting = $container->get(VotingService::class);
        $this->spotify = new FakeSpotifyApi();
        $this->clock = new MockClock('2026-11-10 21:00:00');

        $this->sync = new PlaybackSync(
            $this->spotify,
            $this->suggestions,
            $this->parties,
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

    public function testNoActiveDeviceIsReportedAndNothingIsStarted(): void
    {
        $this->spotify->noActiveDevice = true;

        $result = $this->sync->sync($this->party);

        self::assertSame(Party::SYNC_NO_DEVICE, $result->status);
        self::assertSame(Party::SYNC_NO_DEVICE, $this->party->getSyncStatus());
        self::assertNull($this->party->getNowPlaying());
        self::assertNotContains('play', $this->spotify->callNames());
        self::assertSame(SuggestionStatus::Open, $this->top->getStatus());
    }

    /**
     * Ein Spotify-Konto hat einen Player, also steuert ihn genau eine Party: die zuletzt
     * gestartete. Liefen zwei, buchten beide den laufenden Titel als ihren eigenen und beide
     * schickten ihre Wünsche an denselben Player.
     */
    public function testOnlyTheNewestActivePartyOfAHostSteersSpotify(): void
    {
        $newer = Fixtures::party(
            $this->em,
            $this->party->getHost(),
            $this->clock->now()->modify('+1 minute'),
            code: 'TEST2',
            name: 'Die neue Party',
        );
        $this->spotify->state = $this->playing(Fixtures::track('h', 'Host Song'), progressMs: 185_000, isPlaying: true);

        $result = $this->sync->sync($this->party);

        self::assertSame(Party::SYNC_STANDBY, $result->status);
        self::assertStringContainsString('Die neue Party', (string) $result->message);
        self::assertNull($this->party->getNowPlaying());
        self::assertSame(SuggestionStatus::Open, $this->top->getStatus());
        self::assertSame([], $this->spotify->calls, 'Eine Nebenparty darf Spotify nicht einmal lesen.');

        // Die zuletzt gestartete steuert.
        self::assertSame(Party::SYNC_OK, $this->sync->sync($newer)->status);
    }

    public function testNothingHappensWhileTheCurrentTrackHasPlentyOfTimeLeft(): void
    {
        $this->spotify->state = $this->playing(Fixtures::track('h', 'Host Song'), progressMs: 10_000, isPlaying: true);

        $result = $this->sync->sync($this->party);

        self::assertSame(Party::SYNC_OK, $result->status);
        self::assertSame([], $result->actions);
        self::assertSame('Host Song', $this->party->getNowPlaying()['track']['title']);
        self::assertSame(SuggestionStatus::Open, $this->top->getStatus());
        self::assertNotContains('play', $this->spotify->callNames());
    }

    /**
     * Der Kern des DJ-Modus: Kurz vor dem Ende startet die Büchse den Top-Wunsch selbst. Nichts
     * wandert in Spotifys Warteschlange, also kann sich auch nichts dazwischenschieben.
     */
    public function testTopWishIsStartedShortlyBeforeTheTrackEnds(): void
    {
        $this->spotify->state = $this->playing(Fixtures::track('h', 'Host Song'), progressMs: 198_000, isPlaying: true);

        $result = $this->sync->sync($this->party);

        self::assertSame(Party::SYNC_OK, $result->status);
        self::assertContains(['play', [$this->top->getSpotifyUri()], null], $this->spotify->calls);
        self::assertSame(SuggestionStatus::Playing, $this->top->getStatus());
        self::assertEquals($this->clock->now(), $this->top->getStartedAt());
        self::assertSame(SuggestionStatus::Open, $this->second->getStatus());
        self::assertStringContainsString('Top Wish', (string) $result->message);
    }

    /** Die Rangliste entscheidet im Moment des Wechsels, nicht Minuten vorher. */
    public function testTheRankingDecidesAtTheMomentOfTheSwitch(): void
    {
        $this->spotify->state = $this->playing(Fixtures::track('h', 'Host Song'), progressMs: 10_000, isPlaying: true);
        $this->sync->sync($this->party);
        self::assertNotContains('play', $this->spotify->callNames(), 'Noch ist nichts entschieden.');

        // Kurz vor dem Wechsel zieht ein anderer Wunsch vorbei.
        $clara = Fixtures::guest($this->em, $this->party, $this->clock->now(), str_repeat('c', 48), 'Clara');
        $this->voting->vote($clara, $this->second);
        $this->voting->vote(
            Fixtures::guest($this->em, $this->party, $this->clock->now(), str_repeat('d', 48), 'Dennis'),
            $this->second,
        );

        $this->clock->sleep(PlaybackSync::MIN_INTERVAL_SECONDS);
        $this->spotify->state = $this->playing(Fixtures::track('h', 'Host Song'), progressMs: 199_000, isPlaying: true);
        $this->sync->sync($this->party);

        self::assertContains(['play', [$this->second->getSpotifyUri()], null], $this->spotify->calls);
        self::assertSame(SuggestionStatus::Playing, $this->second->getStatus());
        self::assertSame(SuggestionStatus::Open, $this->top->getStatus(), 'Der überholte Wunsch bleibt in der Liste.');
    }

    public function testIdlePlayerStartsTheTopWishImmediately(): void
    {
        $this->spotify->state = new PlaybackState('dev', 'Küche', false, 0, null, $this->clock->now());

        $this->sync->sync($this->party);

        self::assertContains(['play', [$this->top->getSpotifyUri()], null], $this->spotify->calls);
        self::assertSame(SuggestionStatus::Playing, $this->top->getStatus());
    }

    public function testPausedPlayerIsLeftAlone(): void
    {
        $this->spotify->state = $this->playing(Fixtures::track('h', 'Host Song'), progressMs: 60_000, isPlaying: false);

        $this->sync->sync($this->party);

        self::assertNotContains('play', $this->spotify->callNames());
        self::assertSame(SuggestionStatus::Open, $this->top->getStatus());
    }

    /** Der laufende Wunsch bleibt laufen; erst wenn er nicht mehr läuft, ist er durch. */
    public function testTheRunningWishIsSettledWhenThePlayerHasMovedOn(): void
    {
        $this->top->markPlaying($this->clock->now());
        $this->em->flush();

        // Läuft noch: nichts passiert.
        $this->spotify->state = $this->playing($this->top->toTrackInfo(), progressMs: 5_000, isPlaying: true);
        $this->sync->sync($this->party);
        self::assertSame(SuggestionStatus::Playing, $this->top->getStatus());
        self::assertSame('Anna', $this->party->getNowPlaying()['wishedBy']);
        self::assertNotContains('play', $this->spotify->callNames());

        // Jetzt läuft etwas anderes: abgehakt, und der nächste Wunsch wartet auf seinen Moment.
        $this->clock->sleep(PlaybackSync::MIN_INTERVAL_SECONDS);
        $this->spotify->state = $this->playing(Fixtures::track('h', 'Host Song'), progressMs: 1_000, isPlaying: true);
        $result = $this->sync->sync($this->party);

        self::assertSame(SuggestionStatus::Played, $this->top->getStatus());
        self::assertStringContainsString('Gelaufen', (string) $result->message);
        self::assertSame(SuggestionStatus::Open, $this->second->getStatus());
    }

    /** Zwei Wünsche hintereinander: Am Ende des einen startet die Büchse den nächsten. */
    public function testOneWishFollowsTheOther(): void
    {
        $this->spotify->state = $this->playing($this->top->toTrackInfo(), progressMs: 199_000, isPlaying: true);
        $this->top->markPlaying($this->clock->now());
        $this->em->flush();

        $result = $this->sync->sync($this->party);

        self::assertContains(['play', [$this->second->getSpotifyUri()], null], $this->spotify->calls);
        self::assertSame(SuggestionStatus::Played, $this->top->getStatus(), 'Der Vorgänger ist abgehakt.');
        self::assertSame(SuggestionStatus::Playing, $this->second->getStatus());
        self::assertStringContainsString('Second Wish', (string) $result->message);
    }

    public function testWishStartedManuallyByTheHostBecomesTheRunningTrack(): void
    {
        $this->spotify->state = $this->playing($this->second->toTrackInfo(), progressMs: 5_000, isPlaying: true);

        $this->sync->sync($this->party);

        self::assertSame(SuggestionStatus::Playing, $this->second->getStatus());
        self::assertSame(SuggestionStatus::Open, $this->top->getStatus());
        self::assertSame('Ben', $this->party->getNowPlaying()['wishedBy']);
        self::assertNotContains('play', $this->spotify->callNames(), 'Läuft schon, nicht neu starten.');
    }

    /** Lehnt Spotify den Start ab, gehört der Wunsch zurück in die Rangliste. */
    public function testARefusedStartPutsTheWishBackIntoTheRanking(): void
    {
        $this->spotify->state = new PlaybackState('dev', 'Küche', false, 0, null, $this->clock->now());
        $this->spotify->failPlayback = true;

        $result = $this->sync->sync($this->party);

        self::assertSame(Party::SYNC_ERROR, $result->status);
        self::assertSame(SuggestionStatus::Open, $this->top->getStatus());
        self::assertNull($this->top->getStartedAt());
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

    /**
     * Am Ende des letzten Wunsches übernimmt die Fallback-Playlist – sonst sucht sich Spotify
     * mit Autoplay selbst aus, was danach läuft.
     */
    public function testFallbackPlaylistTakesOverAtTheEndOfTheLastWish(): void
    {
        $this->second->markPlayed($this->clock->now());
        $this->top->markPlaying($this->clock->now());
        $this->party->setFallbackPlaylistUri('spotify:playlist:'.str_repeat('p', 22));
        $this->em->flush();

        $this->spotify->state = $this->playing($this->top->toTrackInfo(), progressMs: 199_000, isPlaying: true);
        $result = $this->sync->sync($this->party);

        self::assertContains(['play', [], 'spotify:playlist:'.str_repeat('p', 22)], $this->spotify->calls);
        self::assertSame(SuggestionStatus::Played, $this->top->getStatus());
        self::assertStringContainsString('Fallback', (string) $result->message);
    }

    /** Die eigene Musik des Hosts fasst die Büchse dabei nicht an. */
    public function testWithoutWishesTheHostsOwnTrackIsNotInterrupted(): void
    {
        $this->top->markPlayed($this->clock->now());
        $this->second->markPlayed($this->clock->now());
        $this->party->setFallbackPlaylistUri('spotify:playlist:'.str_repeat('p', 22));
        $this->em->flush();

        $this->spotify->state = $this->playing(Fixtures::track('h', 'Host Song'), progressMs: 199_000, isPlaying: true);
        $this->sync->sync($this->party);

        self::assertNotContains('play', $this->spotify->callNames());
    }

    /** „Weiter“ in der Host-Ansicht startet selbst den nächsten Wunsch, statt Spotify zu fragen. */
    public function testSkipStartsTheNextWishInsteadOfLettingSpotifyChoose(): void
    {
        $this->top->markPlaying($this->clock->now());
        $this->em->flush();
        $this->spotify->state = $this->playing($this->top->toTrackInfo(), progressMs: 20_000, isPlaying: true);

        $result = $this->sync->skipCurrent($this->party);

        self::assertSame(Party::SYNC_OK, $result->status);
        self::assertSame(SuggestionStatus::Played, $this->top->getStatus());
        self::assertSame(SuggestionStatus::Playing, $this->second->getStatus());
        self::assertContains(['play', [$this->second->getSpotifyUri()], null], $this->spotify->calls);
        self::assertNotContains('next', $this->spotify->callNames());
    }

    /** Ohne offenen Wunsch gehört der Player dem Host: dann wirklich nur weiterspringen. */
    public function testSkipWithoutAnyWishLeftJustSkipsInSpotify(): void
    {
        $this->top->markPlayed($this->clock->now());
        $this->second->markPlayed($this->clock->now());
        $this->em->flush();
        $this->spotify->state = $this->playing(Fixtures::track('h', 'Host Song'), progressMs: 20_000, isPlaying: true);

        $result = $this->sync->skipCurrent($this->party);

        self::assertSame(Party::SYNC_OK, $result->status);
        self::assertContains(['next'], $this->spotify->calls);
    }

    /**
     * Der Fehler vom 11.11.2026: In vier Sekunden war die Wunschliste leer und zu hören war
     * nichts. Spotify quittiert den Start sofort, meldet über GET /me/player aber noch den
     * vorherigen Titel – der nächste Durchgang hielt den Wunsch deshalb für durchgelaufen,
     * hakte ihn ab und startete den nächsten, und so weiter bis zum Ende der Liste.
     */
    public function testAStartedWishIsLeftAloneUntilThePlayerShowsIt(): void
    {
        $this->spotify->state = new PlaybackState('dev', 'Küche', false, 0, null, $this->clock->now());
        $this->sync->sync($this->party);
        self::assertSame(SuggestionStatus::Playing, $this->top->getStatus());

        // Spotify meldet weiter nichts: Anlauffenster, also abwarten.
        $this->clock->sleep(PlaybackSync::MIN_INTERVAL_SECONDS);
        $result = $this->sync->sync($this->party);

        self::assertSame(Party::SYNC_OK, $result->status);
        self::assertSame([], $result->actions, 'Nichts abhaken, solange der Start noch unterwegs ist.');
        self::assertSame(SuggestionStatus::Playing, $this->top->getStatus());
        self::assertSame(SuggestionStatus::Open, $this->second->getStatus(), 'Der nächste Wunsch bleibt liegen.');
        self::assertSame(1, \count(array_filter($this->spotify->callNames(), static fn (string $n): bool => 'play' === $n)));

        // Jetzt läuft er wirklich, und danach ist er auch wirklich durch.
        $this->clock->sleep(PlaybackSync::MIN_INTERVAL_SECONDS);
        $this->spotify->state = $this->playing($this->top->toTrackInfo(), progressMs: 2_000, isPlaying: true);
        $this->sync->sync($this->party);
        self::assertSame(SuggestionStatus::Playing, $this->top->getStatus());
        self::assertNotNull($this->top->getConfirmedAt());

        $this->clock->sleep(PlaybackSync::MIN_INTERVAL_SECONDS);
        $this->spotify->state = $this->playing(Fixtures::track('h', 'Host Song'), progressMs: 1_000, isPlaying: true);
        $result = $this->sync->sync($this->party);

        self::assertSame(SuggestionStatus::Played, $this->top->getStatus());
        self::assertStringContainsString('Gelaufen', (string) $result->message);
    }

    /**
     * Spotify quittiert den Start, spielt aber nichts (schlafendes Gerät). Dann gehört der Wunsch
     * zurück in die Rangliste – und nicht die ganze Liste durchgereicht.
     */
    public function testAStartThatNeverArrivesDoesNotDrainTheList(): void
    {
        $this->spotify->state = new PlaybackState('dev', 'Küche', false, 0, null, $this->clock->now());
        $this->sync->sync($this->party);
        self::assertSame(SuggestionStatus::Playing, $this->top->getStatus());

        $this->clock->sleep(PlaybackSync::STARTUP_SECONDS);
        $result = $this->sync->sync($this->party);

        self::assertSame(Party::SYNC_ERROR, $result->status);
        self::assertStringContainsString('nicht gestartet', (string) $result->message);
        self::assertSame(SuggestionStatus::Open, $this->top->getStatus(), 'Zurück in die Rangliste.');
        self::assertNull($this->top->getStartedAt());
        self::assertSame(SuggestionStatus::Open, $this->second->getStatus(), 'Der nächste Wunsch wird nicht verheizt.');
        self::assertSame(1, \count(array_filter($this->spotify->callNames(), static fn (string $n): bool => 'play' === $n)));
    }

    /**
     * Host-Seite, Display, jedes Gast-Handy und der Hintergrunddienst pollen unabhängig: Mehrere
     * Anfragen passieren die Intervall-Prüfung gleichzeitig und stehen dann hintereinander am
     * Lock. Die zweite Prüfung im Lock verhindert, dass sie alle einen Durchgang laufen.
     */
    public function testAPollThatWasOvertakenAtTheLockDoesNotRunASecondTime(): void
    {
        $this->spotify->state = $this->playing(Fixtures::track('h', 'Host Song'), progressMs: 10_000, isPlaying: true);

        // Ein anderer Poll war schneller: In der Datenbank steht der frische Zeitstempel, dieses
        // Party-Objekt im Speicher kennt ihn noch nicht.
        $this->em->getConnection()->executeStatement(
            'UPDATE party SET last_sync_at = ? WHERE id = ?',
            [$this->clock->now()->format('Y-m-d H:i:s'), $this->party->getId()],
        );

        self::assertNull($this->sync->syncIfDue($this->party));
        self::assertSame([], $this->spotify->calls, 'Kein zweiter Durchgang, also auch kein Spotify-Aufruf.');
    }

    /**
     * Die Nacht zum 12.11.2026: 18 402 × „Too many requests“ im Log, eine Zeile pro Sekunde.
     * Ein Wunsch, den der Player nicht annahm, wurde alle zehn Sekunden neu gestartet, bis
     * Spotify das Kontingent der App sperrte. Nach drei Versuchen ist deshalb Ruhe.
     */
    public function testAfterThreeRefusedStartsThePartyLeavesSpotifyAlone(): void
    {
        $this->spotify->state = new PlaybackState('dev', 'Küche', false, 0, null, $this->clock->now());

        for ($attempt = 1; $attempt <= PlaybackSync::MAX_START_ATTEMPTS; ++$attempt) {
            $this->sync->sync($this->party);                       // startet
            $this->clock->sleep(PlaybackSync::STARTUP_SECONDS);
            $result = $this->sync->sync($this->party);             // kommt nicht an
            self::assertSame(Party::SYNC_ERROR, $result->status);
        }

        self::assertTrue($this->party->isSpotifyQuiet($this->clock->now()), 'Jetzt ist Ruhe.');
        self::assertStringContainsString('wartet', (string) $this->party->getSyncMessage());

        $before = \count($this->spotify->calls);
        $this->clock->sleep(PlaybackSync::IDLE_INTERVAL_SECONDS);
        self::assertNull($this->sync->syncIfDue($this->party), 'Kein Durchgang während der Pause.');
        self::assertSame($before, \count($this->spotify->calls), 'Und kein einziger Aufruf bei Spotify.');

        // Nach der Pause darf sie es wieder versuchen.
        $this->clock->sleep(PlaybackSync::TROUBLE_PAUSE_SECONDS);
        self::assertNotNull($this->sync->syncIfDue($this->party));
    }

    /** Läuft der Wunsch doch an, ist das Zählwerk wieder bei null. */
    public function testAWishThatPlaysClearsTheTrouble(): void
    {
        $this->spotify->state = new PlaybackState('dev', 'Küche', false, 0, null, $this->clock->now());
        $this->sync->sync($this->party);
        $this->clock->sleep(PlaybackSync::STARTUP_SECONDS);
        $this->sync->sync($this->party);
        self::assertSame(1, $this->party->getFailedStarts());

        $this->clock->sleep(PlaybackSync::MIN_INTERVAL_SECONDS);
        $this->spotify->state = $this->playing($this->top->toTrackInfo(), progressMs: 1_000, isPlaying: true);
        $this->sync->sync($this->party);

        self::assertSame(0, $this->party->getFailedStarts());
        self::assertFalse($this->party->isSpotifyQuiet($this->clock->now()));
    }

    /** Drosselt Spotify, hält sich die Büchse genau so lange zurück, wie Spotify verlangt. */
    public function testARateLimitSilencesThePartyForAsLongAsSpotifyAsks(): void
    {
        $this->spotify->failWith = new SpotifyApiException('Too many requests', 429, 'QUOTA_EXCEEDED', null, 120);

        $result = $this->sync->sync($this->party);

        self::assertSame(Party::SYNC_ERROR, $result->status);
        self::assertEquals(
            $this->clock->now()->modify('+120 seconds'),
            $this->party->getSpotifyQuietUntil(),
        );

        $this->spotify->failWith = null;
        $this->clock->sleep(119);
        self::assertNull($this->sync->syncIfDue($this->party), 'Noch Ruhe.');

        $this->clock->sleep(2);
        self::assertNotNull($this->sync->syncIfDue($this->party), 'Danach wieder im Takt.');
    }

    /** Ohne offenen Wunsch und ohne laufenden Titel reicht ein Blick alle halbe Minute. */
    public function testAnIdlePartyAsksSpotifyFarLessOften(): void
    {
        foreach ($this->suggestions->findRanking($this->party) as $wish) {
            $wish->markPlayed($this->clock->now());
        }
        $this->em->flush();
        $this->spotify->state = new PlaybackState('dev', 'Küche', false, 0, null, $this->clock->now());

        self::assertNotNull($this->sync->syncIfDue($this->party));

        $this->clock->sleep(PlaybackSync::MIN_INTERVAL_SECONDS);
        self::assertNull($this->sync->syncIfDue($this->party), 'Zwei Sekunden reichen hier nicht.');

        $this->clock->sleep(PlaybackSync::IDLE_INTERVAL_SECONDS);
        self::assertNotNull($this->sync->syncIfDue($this->party));
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
