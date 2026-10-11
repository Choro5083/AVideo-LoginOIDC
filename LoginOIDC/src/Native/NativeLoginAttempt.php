<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Native;

/** A sign-in that bypasses the provider (username and password, or restoration from the cookie). */
final class NativeLoginAttempt
{
    public function __construct(
        public readonly string $username,
        /** whether the user is linked to the provider */
        public readonly bool $linked,
        /** whether it is a restoration from the remember-me cookie */
        public readonly bool $viaCookie,
        public readonly string $remoteAddress,
    ) {
    }
}
