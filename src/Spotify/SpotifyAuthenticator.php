<?php

declare(strict_types=1);

namespace App\Spotify;

use App\Entity\Host;
use App\Repository\HostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Spotify Authorization Code flow for the host.
 *
 * The host is the only Spotify user of this app. Guests never authenticate, which keeps
 * the app within Spotify's development-mode limits (max. 5 allow-listed users).
 */
class SpotifyAuthenticator
{
    public const AUTHORIZE_URL = 'https://accounts.spotify.com/authorize';

    /** Scopes needed to read the player state, queue tracks and skip. */
    public const SCOPES = [
        'user-read-playback-state',
        'user-modify-playback-state',
        'user-read-currently-playing',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly HostRepository $hosts,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly string $spotifyClientId,
        private readonly string $spotifyClientSecret,
        private readonly string $spotifyRedirectUri,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->spotifyClientId && '' !== $this->spotifyClientSecret;
    }

    public function getRedirectUri(): string
    {
        return $this->spotifyRedirectUri;
    }

    public function getAuthorizeUrl(string $state): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $this->spotifyClientId,
            'response_type' => 'code',
            'redirect_uri' => $this->spotifyRedirectUri,
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            'show_dialog' => 'false',
        ], '', '&', \PHP_QUERY_RFC3986);
    }

    /**
     * Exchanges the authorization code, loads the profile and creates or updates the Host.
     *
     * @throws SpotifyApiException
     */
    public function completeLogin(string $code): Host
    {
        $response = $this->httpClient->request('POST', HostTokenProvider::TOKEN_URL, [
            'auth_basic' => [$this->spotifyClientId, $this->spotifyClientSecret],
            'body' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->spotifyRedirectUri,
            ],
        ]);

        $status = $response->getStatusCode();
        $tokens = $response->toArray(false);
        if (200 !== $status || empty($tokens['access_token']) || empty($tokens['refresh_token'])) {
            throw new SpotifyApiException(
                (string) ($tokens['error_description'] ?? $tokens['error'] ?? 'Token exchange failed'),
                $status,
                'TOKEN_EXCHANGE_FAILED',
            );
        }

        $profileResponse = $this->httpClient->request('GET', SpotifyClient::API_BASE.'/me', [
            'auth_bearer' => $tokens['access_token'],
        ]);
        $profile = $profileResponse->toArray(false);
        if (200 !== $profileResponse->getStatusCode() || empty($profile['id'])) {
            $reason = isset($profile['error']['reason']) ? (string) $profile['error']['reason'] : null;
            throw new SpotifyApiException(
                (string) ($profile['error']['message'] ?? 'Could not load the Spotify profile'),
                $profileResponse->getStatusCode(),
                $reason,
            );
        }

        $now = $this->clock->now();
        $expiresAt = $now->modify(sprintf('+%d seconds', (int) ($tokens['expires_in'] ?? 3600)));

        $host = $this->hosts->findOneBySpotifyUserId((string) $profile['id']);
        if (null === $host) {
            $host = new Host((string) $profile['id'], (string) $tokens['access_token'], (string) $tokens['refresh_token'], $expiresAt, $now);
            $this->entityManager->persist($host);
        } else {
            $host->updateTokens((string) $tokens['access_token'], (string) $tokens['refresh_token'], $expiresAt);
        }
        $host->setDisplayName(isset($profile['display_name']) ? (string) $profile['display_name'] : null);
        $this->entityManager->flush();

        return $host;
    }
}
