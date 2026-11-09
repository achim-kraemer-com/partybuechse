<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Party;
use App\Entity\Suggestion;
use App\Repository\PartyRepository;
use App\Repository\SuggestionRepository;
use App\Spotify\PlaybackState;
use App\Spotify\SpotifyApiException;
use App\Spotify\SpotifyApiInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Keeps the party in step with the host's Spotify player.
 *
 * One run:
 *  1. read the playback state and store a "now playing" snapshot on the party
 *  2. settle wishes that were handed to Spotify (now playing -> played; vanished -> consumed)
 *  3. if nothing of ours is pending in Spotify's queue, hand over the top-voted wish
 *     shortly before the current track ends, or start it right away if the player is idle
 *  4. with an empty ranking and an idle player, start the fallback playlist (if configured)
 *
 * The host keeps playing whatever they like in Spotify; wishes are inserted via the queue,
 * so the host's own playlist acts as a natural fallback.
 *
 * Only the host's most recently started party steers the player; any other active party of the
 * same host goes to SYNC_STANDBY and keeps its hands off Spotify (see Party::SYNC_STANDBY).
 */
class PlaybackSync
{
    /** Hand the next wish to Spotify when at most this much of the current track is left. */
    public const QUEUE_AHEAD_MS = 20_000;

    /**
     * Last resort for a queued wish while Spotify's queue cannot be read: after this long
     * without the wish coming on, it counts as consumed. As long as the queue *can* be read,
     * what it says decides - a wish still listed there waits however long it takes.
     */
    public const STALE_QUEUE_SECONDS = 20 * 60;

    /** Give Spotify this long to reflect a queue change before judging it lost. */
    public const QUEUE_GRACE_SECONDS = 90;

    /** Polling clients trigger a sync at most this often. */
    public const MIN_INTERVAL_SECONDS = 4;

    public function __construct(
        private readonly SpotifyApiInterface $spotify,
        private readonly SuggestionRepository $suggestions,
        private readonly PartyRepository $parties,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Runs a sync unless one ran recently or is running right now. Safe to call from every poll.
     */
    public function syncIfDue(Party $party): ?SyncResult
    {
        if (!$party->isActive()) {
            return null;
        }

        $now = $this->clock->now();
        $last = $party->getLastSyncAt();
        if (null !== $last && ($now->getTimestamp() - $last->getTimestamp()) < self::MIN_INTERVAL_SECONDS) {
            return null;
        }

        $lock = $this->lockFactory->createLock('party-sync-'.$party->getId(), 30.0, false);
        if (!$lock->acquire()) {
            return null;
        }

        try {
            return $this->sync($party);
        } finally {
            $lock->release();
        }
    }

    public function sync(Party $party): SyncResult
    {
        $host = $party->getHost();
        $now = $this->clock->now();
        $actions = [];

        if (!$party->isActive()) {
            return new SyncResult(Party::SYNC_ENDED, 'Die Party ist beendet.');
        }

        // Ein Spotify-Konto hat genau einen Player. Lief eine zweite Party gegen dasselbe
        // Konto, buchten beide den laufenden Titel als ihren eigenen und legten beide Wuensche
        // in dieselbe Warteschlange. Nur die zuletzt gestartete Party steuert.
        $current = $this->parties->findCurrentByHost($host);
        if (null !== $current && $current->getId() !== $party->getId()) {
            $party->setNowPlaying(null);
            $party->setSync(Party::SYNC_STANDBY, sprintf(
                'Die Musik läuft über die Party „%s“ (%s). Beende sie, wenn diese hier übernehmen soll.',
                $current->getName(),
                $current->getCode(),
            ), $now);
            $this->entityManager->flush();

            return new SyncResult(Party::SYNC_STANDBY, $party->getSyncMessage());
        }

        try {
            $state = $this->spotify->getPlaybackState($host);
        } catch (SpotifyApiException $e) {
            if (!$e->isNoActiveDevice()) {
                return $this->fail($party, $e, $now);
            }
            $state = null;
        }

        if (null === $state || null === $state->deviceId) {
            $party->setNowPlaying(null);
            $party->setSync(Party::SYNC_NO_DEVICE, 'Kein aktives Spotify-Gerät. Starte die Wiedergabe in Spotify, dann übernimmt die Partybüchse.', $now);
            $this->entityManager->flush();

            return new SyncResult(Party::SYNC_NO_DEVICE, $party->getSyncMessage());
        }

        $current = $state->track;

        try {
            if (null !== $skipped = $this->skipLeftoverOfAnotherParty($party, $state, $now)) {
                return $skipped;
            }

            $actions = array_merge($actions, $this->settleQueuedWishes($party, $state, $now));

            $nowPlaying = $state->toArray();
            $nowPlaying['wishedBy'] = null;
            if (null !== $current) {
                $wish = $this->suggestions->findLastPlayedByUri($party, $current->uri);
                $nowPlaying['wishedBy'] = $wish?->getSuggestedBy()?->getLabel();
            }
            $party->setNowPlaying($nowPlaying);

            $actions = array_merge($actions, $this->feedSpotify($party, $state, $now));
        } catch (SpotifyApiException $e) {
            $this->entityManager->flush();

            return $this->fail($party, $e, $now, $actions);
        }

        $message = [] === $actions ? null : implode(' · ', $actions);
        $party->setSync(Party::SYNC_OK, $message, $now);
        $this->entityManager->flush();

        if ([] !== $actions) {
            $this->logger->info('Party sync', ['party' => $party->getCode(), 'actions' => $actions]);
        }

        return new SyncResult(Party::SYNC_OK, $message, $actions);
    }

    /**
     * Lets go of a track that this host handed to Spotify for a different party.
     *
     * Spotifys Warteschlange gehört dem Konto, nicht der Party, und die API kann einen Titel
     * nicht wieder herausnehmen. Ein Wunsch, der kurz vor dem Ende seiner Party übergeben
     * wurde, läuft deshalb mitten in der nächsten – die Gäste hören ein Lied, das sich hier
     * niemand gewünscht hat. Verhindern können wir das nicht, aber wir können ihn ziehen
     * lassen, sobald er anfängt.
     *
     * Übersprungen wird höchstens einer pro Durchgang, und nur genau der Titel, der gerade
     * läuft: so entscheidet jedes Mal die Wirklichkeit und nie eine Zählung, die danebenliegen
     * könnte. Drei Überreste sind nach drei Durchgängen weg.
     */
    private function skipLeftoverOfAnotherParty(Party $party, PlaybackState $state, \DateTimeImmutable $now): ?SyncResult
    {
        $current = $state->track;
        if (null === $current || !$state->isPlaying) {
            return null;
        }

        // Steht der Titel auch auf dieser Liste, gehört er hierher und bleibt.
        if (null !== $this->suggestions->findPendingByUri($party, $current->uri)) {
            return null;
        }

        $leftover = $this->suggestions->findQueuedInAnotherParty($party, $current->uri);
        if (null === $leftover) {
            return null;
        }

        // Erst vermerken, dann überspringen. Andersherum würde ein verlorener Schreibvorgang
        // beim nächsten Poll erneut überspringen – und dann träfe es den Titel danach, also
        // womöglich die Musik des Hosts.
        $leftover->markRemoved();
        $this->entityManager->flush();

        $this->spotify->skipToNext($party->getHost(), $state->deviceId);

        $message = sprintf(
            'Übersprungen: „%s“ lag noch aus der Party „%s“ in Spotifys Warteschlange.',
            $current->title,
            $leftover->getParty()->getName(),
        );
        $party->setNowPlaying(null);
        $party->setSync(Party::SYNC_OK, $message, $now);
        $this->entityManager->flush();

        $this->logger->info('Skipped a leftover from another party', [
            'party' => $party->getCode(),
            'from' => $leftover->getParty()->getCode(),
            'wish' => $leftover->getSpotifyUri(),
        ]);

        return new SyncResult(Party::SYNC_OK, $message, [$message]);
    }

    /**
     * Marks queued wishes as played when they come on, and as consumed when they vanished from Spotify's queue.
     *
     * @return string[]
     */
    private function settleQueuedWishes(Party $party, PlaybackState $state, \DateTimeImmutable $now): array
    {
        $actions = [];
        $current = $state->track;
        $queued = $this->suggestions->findQueued($party);

        // A wish the host started by hand in Spotify counts as played, too.
        if (null !== $current) {
            $pending = $this->suggestions->findPendingByUri($party, $current->uri);
            if (null !== $pending && $pending->isOpen()) {
                $pending->markPlayed($now);
                $actions[] = sprintf('Läuft: %s', $this->label($pending));
            }
        }

        if ([] === $queued) {
            $this->entityManager->flush();

            return $actions;
        }

        $queueUris = null;
        foreach ($queued as $wish) {
            if (null !== $current && $wish->getSpotifyUri() === $current->uri) {
                $wish->markPlayed($now);
                $actions[] = sprintf('Läuft: %s', $this->label($wish));
                continue;
            }

            $age = $now->getTimestamp() - ($wish->getQueuedAt()?->getTimestamp() ?? $now->getTimestamp());
            if ($age < self::QUEUE_GRACE_SECONDS) {
                continue;
            }

            $queueUris ??= $this->safeQueueUris($party);

            // Liegt der Titel noch in Spotifys Warteschlange, warten wir – ganz egal, wie lange
            // das dauert. Eine lange Warteschlange braucht ihre Zeit, und ihn nach 20 Minuten
            // als verschwunden zu buchen, obwohl Spotify ihn noch hat, nimmt den Wunsch aus der
            // Liste, bevor er gelaufen ist.
            if (null !== $queueUris && \in_array($wish->getSpotifyUri(), $queueUris, true)) {
                continue;
            }

            if (null === $queueUris) {
                // Warteschlange nicht lesbar: dann bleibt nur das Alter als Anhaltspunkt.
                if ($age >= self::STALE_QUEUE_SECONDS) {
                    $wish->markPlayed($now);
                    $actions[] = sprintf('Aus der Warteschlange verschwunden: %s', $this->label($wish));
                }
                continue;
            }

            $wish->markPlayed($now);
            $actions[] = sprintf('Nicht mehr in der Warteschlange: %s', $this->label($wish));
        }

        $this->entityManager->flush();

        return $actions;
    }

    /**
     * Hands the top wish to Spotify when the time is right, or starts the fallback playlist.
     *
     * @return string[]
     */
    private function feedSpotify(Party $party, PlaybackState $state, \DateTimeImmutable $now): array
    {
        $host = $party->getHost();
        $current = $state->track;

        if ([] !== $this->suggestions->findQueued($party)) {
            return []; // one of ours is already waiting in Spotify's queue
        }

        $remaining = $state->remainingMs();
        $idle = null === $current
            || (!$state->isPlaying && (0 === $state->progressMs || (null !== $remaining && $remaining < 1_500)));

        $top = $this->suggestions->findTopOpen($party);

        if (null !== $top) {
            if ($idle) {
                return $this->handOver(
                    $party,
                    $top,
                    $now,
                    'Gestartet',
                    fn () => $this->spotify->startPlayback($host, [$top->getSpotifyUri()], null, $state->deviceId),
                );
            }

            if ($state->isPlaying && null !== $remaining && $remaining <= self::QUEUE_AHEAD_MS) {
                // Spotify selbst fragen, bevor wir etwas hineinlegen. Geht die Buchhaltung
                // unten verloren, wandert derselbe Titel sonst bei jedem Poll erneut in die
                // Warteschlange – bei 4 Sekunden Pollabstand fünfmal pro Fenster.
                $queueUris = $this->safeQueueUris($party);
                if (null !== $queueUris && \in_array($top->getSpotifyUri(), $queueUris, true)) {
                    // Spotify hat den Titel schon, nur unsere Liste weiss es nicht. Nachtragen,
                    // nicht bloss abbrechen: sonst bleibt der Wunsch oben in der Rangliste
                    // stehen, blockiert dort bei jedem Poll erneut diese Stelle, und der
                    // naechste Wunsch wird nie uebergeben – die Liste bewegt sich nicht mehr.
                    $top->markQueued($now);
                    $this->entityManager->flush();
                    $this->logger->notice('Wish was already in Spotify\'s queue, recorded as queued', [
                        'party' => $party->getCode(),
                        'wish' => $top->getSpotifyUri(),
                    ]);

                    return [sprintf('War schon bei Spotify: %s', $this->label($top))];
                }

                return $this->handOver(
                    $party,
                    $top,
                    $now,
                    'In die Warteschlange',
                    fn () => $this->spotify->addToQueue($host, $top->getSpotifyUri(), $state->deviceId),
                );
            }

            return [];
        }

        $fallback = $party->getFallbackPlaylistUri();
        if ($idle && null !== $fallback) {
            $this->spotify->startPlayback($host, [], $fallback, $state->deviceId);

            return ['Keine Wünsche offen, Fallback-Playlist gestartet'];
        }

        return [];
    }

    /**
     * Hands one wish to Spotify and keeps both sides in step.
     *
     * The wish is recorded as queued *before* the call. The other way round, a lost
     * write leaves Spotify holding the track while our list still shows it as open,
     * and the next poll hands it over again. If Spotify refuses, the wish goes back
     * into the ranking.
     *
     * @param callable(): void $call
     *
     * @return string[]
     */
    private function handOver(Party $party, Suggestion $wish, \DateTimeImmutable $now, string $what, callable $call): array
    {
        $wish->markQueued($now);
        $this->entityManager->flush();

        try {
            $call();
        } catch (SpotifyApiException $e) {
            // Spotify hat geantwortet und abgelehnt: der Titel liegt nicht in der
            // Warteschlange, der Wunsch gehört zurück in die Rangliste.
            $wish->reopen();
            $this->entityManager->flush();
            $this->logger->warning('Spotify refused the hand-over, wish is back in the ranking', [
                'party' => $party->getCode(),
                'wish' => $wish->getSpotifyUri(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } catch (\Throwable $e) {
            // Gar keine Antwort (Zeitüberschreitung, Verbindungsabbruch): ob Spotify den Titel
            // angenommen hat, wissen wir nicht. Der Wunsch bleibt deshalb auf "queued", damit
            // settleQueuedWishes beim nächsten Durchgang in Spotifys Warteschlange nachsieht
            // und die Tatsachen entscheiden. Setzten wir ihn hier zurück und Spotify hätte ihn
            // doch, läge er unsichtbar in der Warteschlange und liefe irgendwann in einer
            // anderen Party an.
            $this->logger->warning('Hand-over to Spotify got no answer, wish stays queued', [
                'party' => $party->getCode(),
                'wish' => $wish->getSpotifyUri(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->logger->info('Wish handed to Spotify', [
            'party' => $party->getCode(),
            'what' => $what,
            'wish' => $wish->getSpotifyUri(),
        ]);

        return [sprintf('%s: %s', $what, $this->label($wish))];
    }

    /** @return string[]|null */
    private function safeQueueUris(Party $party): ?array
    {
        try {
            return $this->spotify->getQueueUris($party->getHost());
        } catch (SpotifyApiException $e) {
            $this->logger->notice('Could not read the Spotify queue', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** @param string[] $actions */
    private function fail(Party $party, SpotifyApiException $e, \DateTimeImmutable $now, array $actions = []): SyncResult
    {
        $this->logger->warning('Party sync failed', ['party' => $party->getCode(), 'error' => $e->getMessage(), 'status' => $e->statusCode]);
        $party->setSync(Party::SYNC_ERROR, $e->userMessage(), $now);
        $this->entityManager->flush();

        return new SyncResult(Party::SYNC_ERROR, $e->userMessage(), $actions);
    }

    private function label(Suggestion $suggestion): string
    {
        return sprintf('%s – %s', $suggestion->getTitle(), $suggestion->getArtists());
    }
}
