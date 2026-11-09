<?php

declare(strict_types=1);

namespace App\Spotify;

class SpotifyApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly ?string $reason = null,
        ?\Throwable $previous = null,
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
            $this->isRateLimited() => 'Spotify bremst gerade (Rate Limit). Nächster Versuch gleich.',
            401 === $this->statusCode => 'Spotify-Anmeldung abgelaufen. Bitte als Host neu anmelden.',
            403 === $this->statusCode => 'Spotify verweigert den Zugriff (403). Ist dein Konto im Developer Dashboard freigeschaltet?',
            default => 'Spotify-Fehler: '.$this->getMessage(),
        };
    }
}
