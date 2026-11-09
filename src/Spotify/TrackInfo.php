<?php

declare(strict_types=1);

namespace App\Spotify;

/**
 * The slice of a Spotify track object the app cares about.
 */
final class TrackInfo
{
    public function __construct(
        public readonly string $uri,
        public readonly string $title,
        public readonly string $artists,
        public readonly ?string $album,
        public readonly ?string $imageUrl,
        public readonly int $durationMs,
        public readonly bool $explicit = false,
    ) {
    }

    /**
     * Builds a TrackInfo from a Spotify "track" (or "episode") object.
     *
     * @param array<string, mixed> $item
     */
    public static function fromApi(array $item): self
    {
        $artists = [];
        foreach ($item['artists'] ?? [] as $artist) {
            if (!empty($artist['name'])) {
                $artists[] = (string) $artist['name'];
            }
        }
        if ([] === $artists && !empty($item['show']['publisher'])) {
            $artists[] = (string) $item['show']['publisher'];
        }

        $images = $item['album']['images'] ?? $item['images'] ?? $item['show']['images'] ?? [];
        $imageUrl = self::pickImage($images);

        return new self(
            uri: (string) ($item['uri'] ?? ''),
            title: (string) ($item['name'] ?? 'Unbekannter Titel'),
            artists: implode(', ', $artists),
            album: isset($item['album']['name']) ? (string) $item['album']['name'] : null,
            imageUrl: $imageUrl,
            durationMs: (int) ($item['duration_ms'] ?? 0),
            explicit: (bool) ($item['explicit'] ?? false),
        );
    }

    /** Prefers the ~300px image; falls back to whatever is there. */
    private static function pickImage(array $images): ?string
    {
        if ([] === $images) {
            return null;
        }
        usort($images, static fn (array $a, array $b): int => ($a['width'] ?? 0) <=> ($b['width'] ?? 0));
        foreach ($images as $image) {
            if (($image['width'] ?? 0) >= 280) {
                return (string) $image['url'];
            }
        }

        return (string) end($images)['url'];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'uri' => $this->uri,
            'title' => $this->title,
            'artists' => $this->artists,
            'album' => $this->album,
            'imageUrl' => $this->imageUrl,
            'durationMs' => $this->durationMs,
            'explicit' => $this->explicit,
        ];
    }
}
