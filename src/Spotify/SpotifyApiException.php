<?php

declare(strict_types=1);

namespace App\Spotify;

class SpotifyApiException extends \RuntimeException
{
    /** Ohne Retry-After-Kopf: so lange wird Spotify in Ruhe gelassen. */
    public const DEFAULT_RETRY_AFTER = 60;

    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly ?string $reason = null,
        ?\Throwable $previous = null,
        /** Sekunden, die Spotify Ruhe haben will (Retry-After bei 429). */
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function isNoActiveDevice(): bool
    {
        return 'NO_ACTIVE_DEVICE' === $this->reason || 404 === $this->statusCode;
    }

    public function isPremiumRequired(): bool
    {
        return 'PREMIUM_REQUIRED' === $this->reason;
    }

    public function isRateLimited(): bool
    {
        return 429 === $this->statusCode;
    }

    /** A short German message for the host dashboard. */
    public function userMessage(): string
    {
        return match (true) {
            $this->isPremiumRequired() => 'Spotify Premium ist für die Wiedergabesteuerung erforderlich.',
            $this->isNoActiveDevice() => 'Kein aktives Spotify-Gerät. Starte die Wiedergabe in Spotify, dann übernimmt die Partybüchse.',
            $this->isRateLimited() => sprintf(
                'Spotify bremst gerade (Rate Limit). Nächster Versuch in %d Sekunden.',
                $this->retryAfter ?? self::DEFAULT_RETRY_AFTER,
            ),
            401 === $this->statusCode => 'Spotify-Anmeldung abgelaufen. Bitte als Host neu anmelden.',
            403 === $this->statusCode => sprintf(
                'Spotify verweigert den Zugriff (403: %s). Ist dein Konto im Developer Dashboard freigeschaltet?',
                $this->getMessage(),
            ),
            default => 'Spotify-Fehler: '.$this->getMessage(),
        };
    }
}
