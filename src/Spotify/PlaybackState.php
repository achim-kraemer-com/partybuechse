<?php

declare(strict_types=1);

namespace App\Spotify;

/**
 * Snapshot of the host's Spotify player (GET /me/player).
 */
final class PlaybackState
{
    public function __construct(
        public readonly ?string $deviceId,
        public readonly ?string $deviceName,
        public readonly bool $isPlaying,
        public readonly int $progressMs,
        public readonly ?TrackInfo $track,
        public readonly \DateTimeImmutable $fetchedAt,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromApi(array $payload, \DateTimeImmutable $fetchedAt): self
    {
        $item = $payload['item'] ?? null;

        return new self(
            deviceId: isset($payload['device']['id']) ? (string) $payload['device']['id'] : null,
            deviceName: isset($payload['device']['name']) ? (string) $payload['device']['name'] : null,
            isPlaying: (bool) ($payload['is_playing'] ?? false),
            progressMs: (int) ($payload['progress_ms'] ?? 0),
            track: \is_array($item) && !empty($item['uri']) ? TrackInfo::fromApi($item) : null,
            fetchedAt: $fetchedAt,
        );
    }

    public function remainingMs(): ?int
    {
        if (null === $this->track) {
            return null;
        }

        return max(0, $this->track->durationMs - $this->progressMs);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'deviceName' => $this->deviceName,
            'isPlaying' => $this->isPlaying,
            'progressMs' => $this->progressMs,
            'track' => $this->track?->toArray(),
            'fetchedAt' => $this->fetchedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
