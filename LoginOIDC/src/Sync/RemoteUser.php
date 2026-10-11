<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Sync;

/** IdP 側のユーザの今の状態。 */
final class RemoteUser
{
    /**
     * @param list<string> $roleValues  権限の元になる値（設定で選んだ種類の割り当て）
     * @param list<string> $groupValues 所属する group の名前とパス
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $name,
        public readonly string $email,
        public readonly ?bool $emailVerified,
        public readonly array $roleValues,
        public readonly array $groupValues,
    ) {
    }
}
