<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Host;
use App\Spotify\PlaybackState;
use App\Spotify\SpotifyApiException;
use App\Spotify\SpotifyApiInterface;
use App\Spotify\TrackInfo;

/**
 * In-memory stand-in for the Spotify Web API.
 */
final class FakeSpotifyApi implements SpotifyApiInterface
{
    public ?PlaybackState $state = null;
    public bool $noActiveDevice = false;

    /** Spotify lehnt den Start ab (z. B. weil das Gerät zwischenzeitlich weg ist). */
    public bool $failPlayback = false;

    /** Ein Start lässt den Titel wirklich anlaufen (sonst quittiert Spotify nur). */
    public bool $playbackStarts = false;

    /** @var array<int, array{id: string, name: string, type: string, is_active: bool, is_restricted: bool}> */
    public array $devices = [];

    /** @var array<int, array<int, mixed>> */
    public array $calls = [];

    /** @var array<string, TrackInfo> keyed by URI */
    public array $tracks = [];

    /** @var array<int, TrackInfo> */
    public array $queue = [];

    public function searchTracks(Host $host, string $query, int $limit = 10): array
    {
        $this->calls[] = ['search', $query];
        $needle = mb_strtolower($query);

        return array_values(array_filter(
            $this->tracks,
            static fn (TrackInfo $t): bool => str_contains(mb_strtolower($t->title.' '.$t->artists), $needle),
        ));
    }

    public function getTrack(Host $host, string $id): ?TrackInfo
    {
        $this->calls[] = ['track', $id];
        foreach ($this->tracks as $track) {
            if (str_ends_with($track->uri, ':'.$id)) {
                return $track;
            }
        }

        return null;
    }

    /** Spotify antwortet auf jeden Aufruf mit diesem Fehler (z. B. eine Drosselung). */
    public ?SpotifyApiException $failWith = null;

    public function getPlaybackState(Host $host): ?PlaybackState
    {
        $this->calls[] = ['state'];
        if (null !== $this->failWith) {
            throw $this->failWith;
        }
        if ($this->noActiveDevice) {
            throw new SpotifyApiException('Player command failed: No active device found', 404, 'NO_ACTIVE_DEVICE');
        }

        return $this->state;
    }

    public function startPlayback(Host $host, array $uris = [], ?string $contextUri = null, ?string $deviceId = null): void
    {
        $this->calls[] = ['play', $uris, $contextUri];
        if ($this->failPlayback) {
            throw new SpotifyApiException('Player command failed: No active device found', 404, 'NO_ACTIVE_DEVICE');
        }

        if ($this->playbackStarts && [] !== $uris && isset($this->tracks[$uris[0]])) {
            $this->state = new PlaybackState(
                $this->state?->deviceId ?? 'dev',
                $this->state?->deviceName ?? 'Küche',
                true,
                0,
                $this->tracks[$uris[0]],
                $this->state?->fetchedAt ?? new \DateTimeImmutable(),
            );
        }
    }

    public function skipToNext(Host $host, ?string $deviceId = null): void
    {
        $this->calls[] = ['next'];
    }

    public function transferPlayback(Host $host, string $deviceId, bool $play = true): void
    {
        $this->calls[] = ['transfer', $deviceId, $play];
    }

    public function addToQueue(Host $host, string $uri, ?string $deviceId = null): void
    {
        $this->calls[] = ['queue', $uri];
        if (isset($this->tracks[$uri])) {
            $this->queue[] = $this->tracks[$uri];
        }
    }

    public function getQueue(Host $host): array
    {
        return $this->queue;
    }

    public function getDevices(Host $host): array
    {
        return $this->devices;
    }

    /** @return string[] names of the calls made so far */
    public function callNames(): array
    {
        return array_column($this->calls, 0);
    }
}
