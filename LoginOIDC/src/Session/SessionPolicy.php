<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Session;

final class SessionPolicy
{
    /**
     * Returns why the session must end, or null when it may continue.
     */
    public static function reasonToEnd(SessionMarker $marker, SessionState $state): ?string
    {
        if (!$state->userActive) {
            return 'the user is disabled or no longer exists';
        }
        if ($state->sessionRevoked) {
            return 'the provider session was logged out';
        }
        // A sign-in in the same second as the revocation ends too. When the order is unknown, stay on the safe side.
        if ($state->allRevokedAt !== null && $marker->loginAt <= $state->allRevokedAt) {
            return 'all sessions of the user were logged out';
        }

        return null;
    }
}
