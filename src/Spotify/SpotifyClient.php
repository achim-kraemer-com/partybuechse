<?php

declare(strict_types=1);

namespace App\Spotify;

use App\Entity\Host;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Thin wrapper around the Spotify Web API endpoints used by the app.
 *
 * Every call is made with the host's token; a 401 triggers one token refresh and a retry.
 *
 * No request sends a "market" parameter. With a user access token Spotify already
 * restricts the result to the country of that account, so the parameter buys us
 * nothing. The explicit "market=from_token" even costs us /search: that endpoint
 * answers 403 "Insufficient client scope" unless the token carries
 * "user-read-private", which SpotifyAuthenticator::SCOPES deliberately does not
 * request. (/tracks happens to tolerate from_token, so the bug only showed up in
 * the guest search.)
 */
class SpotifyClient implements SpotifyApiInterface
{
    public const API_BASE = 'https://api.spotify.com/v1';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly HostTokenProvider $tokenProvider,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function searchTracks(Host $host, string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ('' === $query) {
            return [];
        }

        $data = $this->call($host, 'GET', '/search', [
            'query' => [
                'q' => $query,
                'type' => 'track',
                'limit' => max(1, min(10, $limit)), // development mode caps search results at 10
            ],
        ]);

        $tracks = [];
        foreach ($data['tracks']['items'] ?? [] as $item) {
            if (\is_array($item) && !empty($item['uri'])) {
                $tracks[] = TrackInfo::fromApi($item);
            }
        }

        return $tracks;
    }

    public function getTrack(Host $host, string $id): ?TrackInfo
    {
        if (1 !== preg_match('~^[A-Za-z0-9]{22}$~', $id)) {
            return null;
        }

        try {
            $data = $this->call($host, 'GET', '/tracks/'.$id);
        } catch (SpotifyApiException $e) {
            if (404 === $e->statusCode) {
                return null;
            }
            throw $e;
        }

        return empty($data['uri']) ? null : TrackInfo::fromApi($data);
    }

    public function getPlaybackState(Host $host): ?PlaybackState
    {
        $data = $this->call($host, 'GET', '/me/player', [
            'query' => ['additional_types' => 'track,episode'],
        ]);

        if ([] === $data) {
            return null; // 204: no active device
        }

        return PlaybackState::fromApi($data, $this->clock->now());
    }

    public function startPlayback(Host $host, array $uris = [], ?string $contextUri = null, ?string $deviceId = null): void
    {
        $options = [];
        if (null !== $deviceId) {
            $options['query'] = ['device_id' => $deviceId];
        }
        $body = [];
        if ([] !== $uris) {
            $body['uris'] = array_values($uris);
        } elseif (null !== $contextUri) {
            $body['context_uri'] = $contextUri;
        }
        if ([] !== $body) {
            $options['json'] = $body;
        }
        $this->command($host, 'PUT', '/me/player/play', $options);
    }

    public function skipToNext(Host $host, ?string $deviceId = null): void
    {
        $options = [];
        if (null !== $deviceId) {
            $options['query'] = ['device_id' => $deviceId];
        }
        $this->command($host, 'POST', '/me/player/next', $options);
    }

    public function transferPlayback(Host $host, string $deviceId, bool $play = true): void
    {
        $this->command($host, 'PUT', '/me/player', [
            'json' => ['device_ids' => [$deviceId], 'play' => $play],
        ]);
    }

    public function getDevices(Host $host): array
    {
        $data = $this->call($host, 'GET', '/me/player/devices');
        $devices = [];
        foreach ($data['devices'] ?? [] as $device) {
            $devices[] = [
                'id' => (string) ($device['id'] ?? ''),
                'name' => (string) ($device['name'] ?? ''),
                'type' => (string) ($device['type'] ?? ''),
                'is_active' => (bool) ($device['is_active'] ?? false),
                // Spotify nimmt für ein solches Gerät Befehle an und führt sie nicht aus.
                'is_restricted' => (bool) ($device['is_restricted'] ?? false),
            ];
        }

        return $devices;
    }

    /**
     * Performs an authenticated request; a 401 triggers one token refresh and a retry.
     *
     * @param array<string, mixed> $options
     *
     * @throws SpotifyApiException
     */
    private function send(Host $host, string $method, string $path, array $options = [], bool $retry = true): ResponseInterface
    {
        $options['auth_bearer'] = $this->tokenProvider->getAccessToken($host);

        $response = $this->httpClient->request($method, self::API_BASE.$path, $options);
        $status = $response->getStatusCode();

        if (401 === $status && $retry) {
            $this->logger->notice('Spotify returned 401, refreshing token once', ['path' => $path]);
            $this->tokenProvider->getAccessToken($host, true);

            return $this->send($host, $method, $path, $options, false);
        }

        if ($status >= 400) {
            throw $this->toException($response, $status);
        }

        return $response;
    }

    /**
     * Tells the player to do something. Only the status code is read.
     *
     * These endpoints carry no payload we need, and what they actually send back varies: a
     * player command answered 200 with a body that is not JSON, which used to abort the whole
     * hand-over although Spotify had accepted the track - the wish went back into the ranking,
     * stayed there, and the list stopped moving. A write either worked or it did not; that is
     * what the status code says.
     *
     * @param array<string, mixed> $options
     *
     * @throws SpotifyApiException
     */
    private function command(Host $host, string $method, string $path, array $options = []): void
    {
        $status = $this->send($host, $method, $path, $options)->getStatusCode();

        // 204 heisst „ausgeführt“. Alles andere unter 400 heisst nur „angenommen“: 202 etwa steht
        // für ein Gerät, das Spotify gerade nicht erreicht – der Befehl verfällt dann still.
        // Ohne diese Zeile stand im Log, dass die Büchse gestartet hat, aber nie, was Spotify
        // dazu gesagt hat.
        if (204 !== $status) {
            $this->logger->warning('Spotify accepted a player command without carrying it out', [
                'path' => $path,
                'status' => $status,
            ]);
        }
    }

    /**
     * Performs a request and decodes the JSON body (empty array for 204/empty bodies).
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     *
     * @throws SpotifyApiException
     */
    private function call(Host $host, string $method, string $path, array $options = []): array
    {
        $response = $this->send($host, $method, $path, $options);
        $status = $response->getStatusCode();

        if (204 === $status) {
            return [];
        }

        $content = $response->getContent(false);
        if ('' === trim($content)) {
            return [];
        }

        try {
            $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Mit dem Anfang des Koerpers, sonst steht im Log nur, dass etwas unlesbar war.
            throw new SpotifyApiException(
                sprintf('Unreadable response from Spotify: %s', $this->excerpt($content)),
                $status,
                null,
                $e,
            );
        }

        return \is_array($decoded) ? $decoded : [];
    }

    private function excerpt(string $content): string
    {
        $oneLine = trim((string) preg_replace('~\s+~u', ' ', $content));

        return mb_strimwidth($oneLine, 0, 120, '…');
    }

    private function toException(ResponseInterface $response, int $status): SpotifyApiException
    {
        $message = 'HTTP '.$status;
        $reason = null;
        try {
            $data = $response->toArray(false);
            $message = (string) ($data['error']['message'] ?? $data['error_description'] ?? $data['error'] ?? $message);
            $reason = isset($data['error']['reason']) ? (string) $data['error']['reason'] : null;
        } catch (\Throwable) {
            // keep the generic message
        }

        $this->logger->warning('Spotify API error', ['status' => $status, 'message' => $message, 'reason' => $reason]);

        return new SpotifyApiException($message, $status, $reason);
    }
}
