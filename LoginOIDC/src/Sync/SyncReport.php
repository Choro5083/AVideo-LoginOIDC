<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Sync;

/** Result of a sync. For a dry run, what would have happened. */
final class SyncReport
{
    /**
     * @param list<string> $disabled        users disabled (username and reason)
     * @param list<string> $enabled         users re-enabled
     * @param list<string> $sessionsRevoked users whose sessions were ended for no longer meeting the requirement to sign in
     */
    public function __construct(
        public readonly bool $dryRun,
        public readonly int $checked,
        public readonly array $disabled,
        public readonly array $enabled,
        public readonly array $sessionsRevoked,
        public readonly int $refreshed,
        /** for a dry run, why a real run would stop. null when it would not */
        public readonly ?string $refusal = null,
    ) {
    }

    public function summary(): string
    {
        return sprintf(
            '%schecked %d, disabled %d, re-enabled %d, sessions revoked %d, refreshed %d',
            $this->dryRun ? '[dry-run] ' : '',
            $this->checked,
            count($this->disabled),
            count($this->enabled),
            count($this->sessionsRevoked),
            $this->refreshed,
        );
    }
}
