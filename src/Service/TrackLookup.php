<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Host;
use App\Spotify\SpotifyApiInterface;
use App\Spotify\TrackInfo;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Search with a short cache (saves Spotify quota when several guests type the same thing)
 * and a track-by-URI lookup backed by the same cache, so a wish never trusts client-sent metadata.
 */
class TrackLookup
{
    private const SEARCH_TTL = 120;
    private const TRACK_TTL = 6 * 3600;

    public function __construct(
        private readonly SpotifyApiInterface $spotify,
        private readonly CacheItemPoolInterface $spotifySearchCache,
    ) {
    }

    /**
     * @return TrackInfo[]
     */
    public function search(Host $host, string $query): array
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
        if (mb_strlen($query) < 2) {
            return [];
        }
        $query = mb_substr($query, 0, 100);

        $item = $this->spotifySearchCache->getItem('search.'.$host->getId().'.'.hash('xxh128', mb_strtolower($query)));
        if ($item->isHit() && \is_array($item->get())) {
            /** @var TrackInfo[] $cached */
            $cached = $item->get();

            return $cached;
        }

        $tracks = $this->spotify->searchTracks($host, $query, 10);

        $item->set($tracks)->expiresAfter(self::SEARCH_TTL);
        $this->spotifySearchCache->save($item);
        foreach ($tracks as $track) {
            $this->remember($track);
        }

        return $tracks;
    }

    public function byUri(Host $host, string $uri): ?TrackInfo
    {
        if (!SpotifyUri::isTrackUri($uri)) {
            return null;
        }

        $item = $this->spotifySearchCache->getItem(self::trackKey($uri));
        if ($item->isHit() && $item->get() instanceof TrackInfo) {
            return $item->get();
        }

        $id = substr($uri, (int) strrpos($uri, ':') + 1);
        $track = $this->spotify->getTrack($host, $id);
        if (null !== $track) {
            $this->remember($track);
        }

        return $track;
    }

    private function remember(TrackInfo $track): void
    {
        $item = $this->spotifySearchCache->getItem(self::trackKey($track->uri));
        $item->set($track)->expiresAfter(self::TRACK_TTL);
        $this->spotifySearchCache->save($item);
    }

    private static function trackKey(string $uri): string
    {
        return 'track.'.hash('xxh128', $uri);
    }
}
