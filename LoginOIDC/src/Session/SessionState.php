<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Session;

/** What the database records about a user and a session. */
final class SessionState
{
    public function __construct(
        /** whether the user exists and is enabled */
        public readonly bool $userActive,
        /** when every session of the user was revoked (UNIX seconds), or null if never */
        public readonly ?int $allRevokedAt,
        /** whether this provider session (sid) was revoked */
        public readonly bool $sessionRevoked,
    ) {
    }
}
