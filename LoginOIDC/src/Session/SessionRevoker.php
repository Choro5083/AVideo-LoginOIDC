<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Session;

interface SessionRevoker
{
    /** ユーザの、この時刻までに始まったセッションをすべて終わらせる。 */
    public function revokeAllOfUser(int $userId, int $now): int;
}
