<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Sync;

/** Looks users up at the provider through its admin API. */
interface RemoteDirectory
{
    /**
     * @return RemoteUser|null null when the user does not exist
     * @throws SyncException when the lookup failed (network failure, missing permission and so on).
     *                       This is kept apart from "does not exist".
     */
    public function lookup(string $subject): ?RemoteUser;
}
