<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Claims;

/** Reads claims by a dot-separated path (for example resource_access.avideo.roles). */
final class ClaimPath
{
    /**
     * Returns the value at the path as a list of strings.
     *
     * An array gives its string elements, a string gives a one-element list. A missing path,
     * or a value that is neither a string nor an array, gives an empty list.
     *
     * @param  array<string, mixed> $claims
     * @return list<string>
     */
    public static function values(array $claims, string $path): array
    {
        $value = self::resolve($claims, $path);
        if (is_string($value)) {
            return $value === '' ? [] : [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $v): bool => is_string($v) && $v !== ''));
    }

    /**
     * Returns the string at the path. An empty string when it is missing or not a string.
     *
     * @param array<string, mixed> $claims
     */
    public static function string(array $claims, string $path): string
    {
        $value = self::resolve($claims, $path);

        return is_string($value) ? $value : '';
    }

    /**
     * A key can contain dots itself (a Keycloak client ID "my.client" gives
     * resource_access.my.client.roles). At each level, the longest prefix of the
     * remaining path is tried as the key first.
     *
     * @param array<mixed> $node
     */
    private static function resolve(array $node, string $path): mixed
    {
        if ($path === '') {
            return null;
        }
        if (array_key_exists($path, $node)) {
            return $node[$path];
        }

        $segments = explode('.', $path);
        for ($length = count($segments) - 1; $length >= 1; $length--) {
            $key = implode('.', array_slice($segments, 0, $length));
            if (array_key_exists($key, $node) && is_array($node[$key])) {
                $found = self::resolve($node[$key], implode('.', array_slice($segments, $length)));
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
