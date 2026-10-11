<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

/** Discovery の結果と JWKS を置く場所。 */
interface Cache
{
    /** @return array<string, mixed>|null 無いか、期限が切れていれば null */
    public function get(string $key): ?array;

    /** @param array<string, mixed> $value */
    public function set(string $key, array $value, int $ttlSeconds): void;

    public function delete(string $key): void;
}
