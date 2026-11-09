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
 * restricts the result to the country of that account, which is what we want. The
 * explicit "market=from_token" additionally needs the "user-read-private" scope –
 * without it every call is answered with 403 "Insufficient client scope", and that
 * scope is deliberately not requested (see SpotifyAuthenticator::SCOPES).
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

    public function addToQueue(Host $host, string $uri, ?string $deviceId = null): void
    {
        $query = ['uri' => $uri];
        if (null !== $deviceId) {
            $query['device_id'] = $deviceId;
        }
        $this->call($host, 'POST', '/me/player/queue', ['query' => $query]);
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
        $this->call($host, 'PUT', '/me/player/play', $options);
    }

    public function skipToNext(Host $host, ?string $deviceId = null): void
    {
        $options = [];
        if (null !== $deviceId) {
            $options['query'] = ['device_id' => $deviceId];
        }
        $this->call($host, 'POST', '/me/player/next', $options);
    }

    public function getQueueUris(Host $host): array
    {
        $data = $this->call($host, 'GET', '/me/player/queue');
        $uris = [];
        if (!empty($data['currently_playing']['uri'])) {
            $uris[] = (string) $data['currently_playing']['uri'];
        }
        foreach ($data['queue'] ?? [] as $item) {
            if (!empty($item['uri'])) {
                $uris[] = (string) $item['uri'];
            }
        }

        return $uris;
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
            ];
        }

        return $devices;
    }

    /**
     * Performs an authenticated request and decodes the JSON body (empty array for 204/empty bodies).
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     *
     * @throws SpotifyApiException
     */
    private function call(Host $host, string $method, string $path, array $options = [], bool $retry = true): array
    {
        $token = $this->tokenProvider->getAccessToken($host);
        $options['auth_bearer'] = $token;

        $response = $this->httpClient->request($method, self::API_BASE.$path, $options);
        $status = $response->getStatusCode();

        if (401 === $status && $retry) {
            $this->logger->notice('Spotify returned 401, refreshing token once', ['path' => $path]);
            $this->tokenProvider->getAccessToken($host, true);

            return $this->call($host, $method, $path, $options, false);
        }

        if ($status >= 400) {
            throw $this->toException($response, $status);
        }

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
            throw new SpotifyApiException('Unreadable response from Spotify', $status, null, $e);
        }

        return \is_array($decoded) ? $decoded : [];
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
