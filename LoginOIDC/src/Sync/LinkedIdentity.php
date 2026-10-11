<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Sync;

/** IdP に紐付いた AVideo のユーザ。 */
final class LinkedIdentity
{
    public function __construct(
        public readonly int $userId,
        public readonly string $username,
        public readonly string $subject,
        public readonly bool $active,
        /** 前回までの同期が無効化したユーザか */
        public readonly bool $disabledBySync,
    ) {
    }
}
