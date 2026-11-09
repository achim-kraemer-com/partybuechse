<?php

declare(strict_types=1);

namespace App\Entity;

enum SuggestionStatus: string
{
    /** In the ranking, waiting for votes. */
    case Open = 'open';

    /** Handed to Spotify's queue, will play next. */
    case Queued = 'queued';

    /** Has been played (or is playing right now). */
    case Played = 'played';

    /** Removed by the host. */
    case Removed = 'removed';

    /** Statuses that still bind a guest's vote. */
    public function bindsVotes(): bool
    {
        return match ($this) {
            self::Open, self::Queued => true,
            self::Played, self::Removed => false,
        };
    }
}
