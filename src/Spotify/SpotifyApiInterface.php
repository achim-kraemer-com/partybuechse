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

    /**
     * Starts exactly these tracks (or a playlist context) on the host's player.
     *
     * The app plays every wish itself; nothing is ever put into Spotify's queue, because that
     * queue belongs to the account and the Web API cannot take anything out of it again.
     *
     * @param string[] $uris
     *
     * @throws SpotifyApiException
     */
    public function startPlayback(Host $host, array $uris = [], ?string $contextUri = null, ?string $deviceId = null): void;

    /** @throws SpotifyApiException */
    public function skipToNext(Host $host, ?string $deviceId = null): void;

    /**
     * @return array<int, array{id: string, name: string, type: string, is_active: bool, is_restricted: bool}>
     *
     * @throws SpotifyApiException
     */
    public function getDevices(Host $host): array;
}
