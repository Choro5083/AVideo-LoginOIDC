<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\User;

final class ProvisionResult
{
    public function __construct(
        public readonly int $userId,
        public readonly string $username,
        public readonly bool $created,
    ) {
    }
}
