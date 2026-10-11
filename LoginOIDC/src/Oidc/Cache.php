<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

/** Where the discovery result and the JWKS are kept. */
interface Cache
{
    /** @return array<string, mixed>|null null when absent or expired */
    public function get(string $key): ?array;

    /** @param array<string, mixed> $value */
    public function set(string $key, array $value, int $ttlSeconds): void;

    public function delete(string $key): void;
}
