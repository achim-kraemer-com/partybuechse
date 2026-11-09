<?php

declare(strict_types=1);

namespace App\Spotify;

use App\Entity\Host;

/**
 * Everything the app needs from the Spotify Web API, always on behalf of a host.
 */
interface SpotifyApiInterface
{
    /**
     * @return TrackInfo[]
     *
     * @throws SpotifyApiException
     */
    public function searchTracks(Host $host, string $query, int $limit = 10): array;

    /**
     * A single track by Spotify ID (used when a wish arrives after the search cache expired).
     *
     * @throws SpotifyApiException
     */
    public function getTrack(Host $host, string $id): ?TrackInfo;

    /**
     * Null when there is no active device / nothing to report (HTTP 204).
     *
     * @throws SpotifyApiException
     */
    public function getPlaybackState(Host $host): ?PlaybackState;

    /** @throws SpotifyApiException */
    public function addToQueue(Host $host, string $uri, ?string $deviceId = null): void;

    /**
     * @param string[] $uris
     *
     * @throws SpotifyApiException
     */
    public function startPlayback(Host $host, array $uris = [], ?string $contextUri = null, ?string $deviceId = null): void;

    /** @throws SpotifyApiException */
    public function skipToNext(Host $host, ?string $deviceId = null): void;

    /**
     * URIs of the currently playing item (first) and the upcoming queue.
     *
     * @return string[]
     *
     * @throws SpotifyApiException
     */
    public function getQueueUris(Host $host): array;

    /**
     * @return array<int, array{id: string, name: string, type: string, is_active: bool}>
     *
     * @throws SpotifyApiException
     */
    public function getDevices(Host $host): array;
}
