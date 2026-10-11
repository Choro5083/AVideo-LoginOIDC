<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Native;

/** IdP を経由しないログイン（ユーザ名とパスワード、または Cookie からの復元）。 */
final class NativeLoginAttempt
{
    public function __construct(
        public readonly string $username,
        /** このユーザが IdP に紐付いているか */
        public readonly bool $linked,
        /** ログイン状態を復元する Cookie によるものか */
        public readonly bool $viaCookie,
        public readonly string $remoteAddress,
    ) {
    }
}
