<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Sync;

/** An AVideo user linked to the provider. */
final class LinkedIdentity
{
    public function __construct(
        public readonly int $userId,
        public readonly string $username,
        public readonly string $subject,
        public readonly bool $active,
        /** whether an earlier sync disabled the user */
        public readonly bool $disabledBySync,
    ) {
    }
}
