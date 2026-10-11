<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\AVideo;

use LoginOIDC\Session\SessionMarker;
use LoginOIDC\Session\SessionRevoker;
use LoginOIDC\Session\SessionState;

/** Records provider sessions and revokes them. */
final class SessionRegistry implements SessionRevoker
{
    /** Older records are removed whenever someone signs in. */
    private const RETENTION_SECONDS = 90 * 86400;

    public function record(SessionMarker $marker): void
    {
        Db::execute('DELETE FROM login_oidc_sessions WHERE created_at < ?', 'i', [$marker->loginAt - self::RETENTION_SECONDS]);
        if ($marker->sessionId === '') {
            return;
        }
        // The same sid can sign in again (the provider session lives on while only AVideo was signed out).
        Db::execute('DELETE FROM login_oidc_sessions WHERE sid = ?', 's', [$marker->sessionId]);
        Db::execute(
            'INSERT INTO login_oidc_sessions (users_id, sid, created_at) VALUES (?, ?, ?)',
            'isi',
            [$marker->userId, $marker->sessionId, $marker->loginAt],
        );
    }

    public function state(SessionMarker $marker): SessionState
    {
        $rows = Db::select(
            'SELECT u.status, i.sessions_revoked_at,'
            . ' (SELECT COUNT(*) FROM login_oidc_sessions s'
            . '   WHERE s.users_id = u.id AND s.sid = ? AND s.revoked_at IS NOT NULL) AS sid_revoked'
            . ' FROM users u LEFT JOIN login_oidc_identities i ON i.users_id = u.id WHERE u.id = ?',
            'si',
            [$marker->sessionId, $marker->userId],
        );
        if ($rows === []) {
            return new SessionState(false, null, false);
        }

        return new SessionState(
            $rows[0]['status'] === 'a',
            $rows[0]['sessions_revoked_at'] === null ? null : (int) $rows[0]['sessions_revoked_at'],
            (int) $rows[0]['sid_revoked'] > 0,
        );
    }

    /** @return int number of sessions revoked */
    public function revokeSession(string $sid, int $now): int
    {
        return Db::execute(
            'UPDATE login_oidc_sessions SET revoked_at = ? WHERE sid = ? AND revoked_at IS NULL',
            'is',
            [$now, $sid],
        );
    }

    /** @return int number of users affected (0 or 1) */
    public function revokeAllOfSubject(string $issuer, string $subject, int $now): int
    {
        return Db::execute(
            'UPDATE login_oidc_identities SET sessions_revoked_at = ?, modified = NOW() WHERE issuer = ? AND subject = ?',
            'iss',
            [$now, $issuer, $subject],
        );
    }

    public function revokeAllOfUser(int $userId, int $now): int
    {
        return Db::execute(
            'UPDATE login_oidc_identities SET sessions_revoked_at = ?, modified = NOW() WHERE users_id = ?',
            'ii',
            [$now, $userId],
        );
    }

    public function isLinked(int $userId): bool
    {
        return Db::select('SELECT 1 FROM login_oidc_identities WHERE users_id = ? LIMIT 1', 'i', [$userId]) !== [];
    }
}
