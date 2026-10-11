<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\User;

/** AVideo のユーザのうち、このプラグインが見る部分。 */
final class LocalUser
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly bool $active,
    ) {
    }
}
