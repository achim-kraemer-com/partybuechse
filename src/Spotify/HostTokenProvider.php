<?php

declare(strict_types=1);

namespace App\Spotify;

use App\Entity\Host;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Hands out a valid access token for a host, refreshing it with Spotify when needed.
 */
class HostTokenProvider
{
    public const TOKEN_URL = 'https://accounts.spotify.com/api/token';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly string $spotifyClientId,
        private readonly string $spotifyClientSecret,
    ) {
    }

    /** @throws SpotifyApiException */
    public function getAccessToken(Host $host, bool $forceRefresh = false): string
    {
        if ($forceRefresh || $host->isTokenExpiringWithin($this->clock->now(), 60)) {
            $this->refresh($host);
        }

        return $host->getAccessToken();
    }

    /** @throws SpotifyApiException */
    public function refresh(Host $host): void
    {
        $this->logger->info('Refreshing Spotify token', ['host' => $host->getSpotifyUserId()]);

        $response = $this->httpClient->request('POST', self::TOKEN_URL, [
            'auth_basic' => [$this->spotifyClientId, $this->spotifyClientSecret],
            'body' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $host->getRefreshToken(),
            ],
        ]);

        $status = $response->getStatusCode();
        $data = $response->toArray(false);

        if (200 !== $status || empty($data['access_token'])) {
            throw new SpotifyApiException(
                (string) ($data['error_description'] ?? $data['error'] ?? 'Token refresh failed'),
                401,
                'TOKEN_REFRESH_FAILED',
            );
        }

        $expiresAt = $this->clock->now()->modify(sprintf('+%d seconds', (int) ($data['expires_in'] ?? 3600)));
        $host->updateTokens((string) $data['access_token'], $data['refresh_token'] ?? null, $expiresAt);
        $this->entityManager->flush();
    }
}
