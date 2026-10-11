<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Session;

final class SessionPolicy
{
    /**
     * セッションを終わらせるべき理由を返す。続けてよければ null。
     */
    public static function reasonToEnd(SessionMarker $marker, SessionState $state): ?string
    {
        if (!$state->userActive) {
            return 'the user is disabled or no longer exists';
        }
        if ($state->sessionRevoked) {
            return 'the provider session was logged out';
        }
        // 無効化と同じ秒のログインも終わらせる。どちらが先か分からないときは、安全な側に倒す。
        if ($state->allRevokedAt !== null && $marker->loginAt <= $state->allRevokedAt) {
            return 'all sessions of the user were logged out';
        }

        return null;
    }
}
