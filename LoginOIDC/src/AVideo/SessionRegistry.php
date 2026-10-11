<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\AVideo;

/**
 * IdP のセッション（sid）と PHP のセッションの対応を記録する。
 *
 * Back-Channel Logout と、同期で無効化したユーザのセッションを切るのに使う。
 */
final class SessionRegistry
{
    /** @param string $sid IdP のセッションの ID。IdP が返さなければ空文字 */
    public function record(int $userId, string $phpSessionId, string $sid): void
    {
        if ($phpSessionId === '') {
            return;
        }
        Db::execute('DELETE FROM login_oidc_sessions WHERE php_session_id = ?', 's', [$phpSessionId]);
        Db::execute(
            'INSERT INTO login_oidc_sessions (users_id, php_session_id, sid, created) VALUES (?, ?, ?, NOW())',
            'iss',
            [$userId, $phpSessionId, $sid === '' ? null : $sid],
        );
    }
}
