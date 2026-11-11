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
     * Übergibt die Wiedergabe an ein Gerät (PUT /me/player).
     *
     * Der von Spotify vorgesehene Weg, ein Gerät zu übernehmen. Ein „play“ mit device_id tut das
     * nebenbei mit, aber eben nicht immer: Auf dem Konto der Partybüchse brach dabei die
     * laufende Wiedergabe ab, ohne dass der gewünschte Titel anlief.
     *
     * @throws SpotifyApiException
     */
    public function transferPlayback(Host $host, string $deviceId, bool $play = true): void;

    /**
     * Reiht einen Titel in die Warteschlange des Kontos ein (POST /me/player/queue).
     *
     * Die Mechanik vor dem DJ-Modus. Sie bleibt hier, weil sie die eine Gegenprobe ist, wenn
     * Spotify ein „play“ quittiert und nichts spielt: Kommt der Titel in der Warteschlange an,
     * nimmt der Player sehr wohl Befehle entgegen.
     *
     * @throws SpotifyApiException
     */
    public function addToQueue(Host $host, string $uri, ?string $deviceId = null): void;

    /**
     * @return array<int, TrackInfo> Was Spotify als Nächstes spielen würde
     *
     * @throws SpotifyApiException
     */
    public function getQueue(Host $host): array;

    /**
     * @return array<int, array{id: string, name: string, type: string, is_active: bool, is_restricted: bool}>
     *
     * @throws SpotifyApiException
     */
    public function getDevices(Host $host): array;
}
