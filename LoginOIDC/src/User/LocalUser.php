<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\User;

/** The part of an AVideo user this plugin looks at. */
final class LocalUser
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly bool $active,
    ) {
    }
}
