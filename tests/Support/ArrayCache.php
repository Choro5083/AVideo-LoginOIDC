<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Tests\Support;

use LoginOIDC\Oidc\Cache;

final class ArrayCache implements Cache
{
    /** @var array<string, array<string, mixed>> */
    public array $entries = [];

    public function get(string $key): ?array
    {
        return $this->entries[$key] ?? null;
    }

    public function set(string $key, array $value, int $ttlSeconds): void
    {
        $this->entries[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->entries[$key]);
    }
}
