<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Party;
use App\Entity\Suggestion;
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
 */
class PlaybackSync
{
    /** Hand the next wish to Spotify when at most this much of the current track is left. */
    public const QUEUE_AHEAD_MS = 20_000;

    /** A queued wish that has not shown up for this long is considered consumed. */
    public const STALE_QUEUE_SECONDS = 20 * 60;

    /** Give Spotify this long to reflect a queue change before judging it lost. */
    public const QUEUE_GRACE_SECONDS = 90;

    /** Polling clients trigger a sync at most this often. */
    public const MIN_INTERVAL_SECONDS = 4;

    public function __construct(
        private readonly SpotifyApiInterface $spotify,
        private readonly SuggestionRepository $suggestions,
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

            if ($age >= self::STALE_QUEUE_SECONDS) {
                $wish->markPlayed($now);
                $actions[] = sprintf('Aus der Warteschlange verschwunden: %s', $this->label($wish));
                continue;
            }

            $queueUris ??= $this->safeQueueUris($party);
            if (null !== $queueUris && !\in_array($wish->getSpotifyUri(), $queueUris, true)) {
                $wish->markPlayed($now);
                $actions[] = sprintf('Nicht mehr in der Warteschlange: %s', $this->label($wish));
            }
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
                $this->spotify->startPlayback($host, [$top->getSpotifyUri()], null, $state->deviceId);
                $top->markQueued($now);
                $this->entityManager->flush();

                return [sprintf('Gestartet: %s', $this->label($top))];
            }

            if ($state->isPlaying && null !== $remaining && $remaining <= self::QUEUE_AHEAD_MS) {
                $this->spotify->addToQueue($host, $top->getSpotifyUri(), $state->deviceId);
                $top->markQueued($now);
                $this->entityManager->flush();

                return [sprintf('In die Warteschlange: %s', $this->label($top))];
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
