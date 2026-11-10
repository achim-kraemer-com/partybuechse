<?php

declare(strict_types=1);

namespace App\Entity;

enum SuggestionStatus: string
{
    /** In the ranking, waiting for votes. */
    case Open = 'open';

    /**
     * Started by the Partybüchse (or by the host in Spotify) and playing right now.
     *
     * There is at most one of these per party: the app starts every track itself, one at a
     * time, instead of filling Spotify's queue. "playing" sorts before "open" (DESC), which is
     * what keeps the running track at the top of the ranking.
     */
    case Playing = 'playing';

    /** Has been played. */
    case Played = 'played';

    /** Removed by the host. */
    case Removed = 'removed';

    /** Statuses that still bind a guest's vote. */
    public function bindsVotes(): bool
    {
        return match ($this) {
            self::Open, self::Playing => true,
            self::Played, self::Removed => false,
        };
    }
}
