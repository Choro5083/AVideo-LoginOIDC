<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Sync;

/** Current state of a user at the provider. */
final class RemoteUser
{
    /**
     * @param list<string> $roleValues  values permissions derive from (assignments of the configured kind)
     * @param list<string> $groupValues names and paths of the user's groups
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $name,
        public readonly string $email,
        public readonly ?bool $emailVerified,
        public readonly array $roleValues,
        public readonly array $groupValues,
    ) {
    }
}
