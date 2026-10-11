<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Sync;

/** IdP のユーザを、管理用の API で調べる。 */
interface RemoteDirectory
{
    /**
     * @return RemoteUser|null ユーザが存在しなければ null
     * @throws SyncException 調べられなかったとき（通信の失敗、権限の不足など）。
     *                       「存在しない」とは区別する。
     */
    public function lookup(string $subject): ?RemoteUser;
}
