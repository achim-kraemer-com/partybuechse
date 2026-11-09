<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What one PlaybackSync run did, for logging and the host dashboard.
 */
final class SyncResult
{
    /** @param string[] $actions */
    public function __construct(
        public readonly string $status,
        public readonly ?string $message = null,
        public readonly array $actions = [],
    ) {
    }
}
