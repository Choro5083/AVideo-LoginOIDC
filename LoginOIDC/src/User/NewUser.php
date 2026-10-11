<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\User;

final class NewUser
{
    public function __construct(
        public readonly string $username,
        public readonly string $name,
        public readonly string $email,
        public readonly bool $emailVerified,
    ) {
    }
}
