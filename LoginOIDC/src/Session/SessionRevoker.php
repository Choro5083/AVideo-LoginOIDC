<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Session;

interface SessionRevoker
{
    /** Ends every session of the user that started up to this moment. */
    public function revokeAllOfUser(int $userId, int $now): int;
}
