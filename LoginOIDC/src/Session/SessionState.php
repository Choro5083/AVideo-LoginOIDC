<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Session;

/** あるユーザとセッションについて、DB に記録されている状態。 */
final class SessionState
{
    public function __construct(
        /** ユーザが存在し、有効か */
        public readonly bool $userActive,
        /** このユーザの全セッションを無効にした時刻（UNIX 秒）。していなければ null */
        public readonly ?int $allRevokedAt,
        /** この IdP セッション（sid）が無効にされたか */
        public readonly bool $sessionRevoked,
    ) {
    }
}
