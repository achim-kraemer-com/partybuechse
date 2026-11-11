<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Was der Abgleich mit dem Player vorgefunden hat, bevor die Büchse etwas startet.
 *
 * Drei Fälle, die ein PlaybackSync-Durchgang unterscheiden muss:
 *  - der Normalfall: nichts oder ein abgehakter Wunsch ($actions),
 *  - der gerade gestartete Wunsch ist noch nicht im Player angekommen ($hold: abwarten),
 *  - der Startbefehl ist gar nicht angekommen ($problem: melden, nichts Neues starten).
 */
final class Settlement
{
    /** @param string[] $actions */
    public function __construct(
        public readonly array $actions = [],
        public readonly bool $hold = false,
        public readonly ?string $problem = null,
    ) {
    }
}
