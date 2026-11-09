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

    /** @var string[] upcoming queue (URIs) */
    public array $queue = [];

    /** @var array<int, array<int, mixed>> */
    public array $calls = [];

    /** @var array<string, TrackInfo> keyed by URI */
    public array $tracks = [];

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

    public function getPlaybackState(Host $host): ?PlaybackState
    {
        $this->calls[] = ['state'];
        if ($this->noActiveDevice) {
            throw new SpotifyApiException('Player command failed: No active device found', 404, 'NO_ACTIVE_DEVICE');
        }

        return $this->state;
    }

    public function addToQueue(Host $host, string $uri, ?string $deviceId = null): void
    {
        $this->calls[] = ['queue', $uri];
        $this->queue[] = $uri;
    }

    public function startPlayback(Host $host, array $uris = [], ?string $contextUri = null, ?string $deviceId = null): void
    {
        $this->calls[] = ['play', $uris, $contextUri];
    }

    public function skipToNext(Host $host, ?string $deviceId = null): void
    {
        $this->calls[] = ['next'];
    }

    public function getQueueUris(Host $host): array
    {
        $this->calls[] = ['queue-list'];
        $uris = [];
        if (null !== $this->state?->track) {
            $uris[] = $this->state->track->uri;
        }

        return array_merge($uris, $this->queue);
    }

    public function getDevices(Host $host): array
    {
        return [];
    }

    /** @return string[] names of the calls made so far */
    public function callNames(): array
    {
        return array_column($this->calls, 0);
    }
}
