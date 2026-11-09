<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Small helpers for Spotify URIs and share links.
 */
final class SpotifyUri
{
    /**
     * Accepts "spotify:playlist:ID", "https://open.spotify.com/playlist/ID?si=..." or a bare ID
     * and returns the canonical "spotify:playlist:ID" (or null for empty / invalid input).
     */
    public static function normalizePlaylist(?string $input): ?string
    {
        $input = trim((string) $input);
        if ('' === $input) {
            return null;
        }

        if (preg_match('~^spotify:(playlist|album|artist):([A-Za-z0-9]{22})$~', $input, $m)) {
            return sprintf('spotify:%s:%s', $m[1], $m[2]);
        }

        if (preg_match('~open\.spotify\.com/(?:intl-[a-z]{2}/)?(playlist|album|artist)/([A-Za-z0-9]{22})~', $input, $m)) {
            return sprintf('spotify:%s:%s', $m[1], $m[2]);
        }

        if (preg_match('~^[A-Za-z0-9]{22}$~', $input)) {
            return 'spotify:playlist:'.$input;
        }

        return null;
    }

    public static function isTrackUri(string $uri): bool
    {
        return 1 === preg_match('~^spotify:(track|episode):[A-Za-z0-9]{22}$~', $uri);
    }

    public static function toOpenUrl(string $uri): ?string
    {
        if (preg_match('~^spotify:(track|episode|playlist|album|artist):([A-Za-z0-9]{22})$~', $uri, $m)) {
            return sprintf('https://open.spotify.com/%s/%s', $m[1], $m[2]);
        }

        return null;
    }
}
