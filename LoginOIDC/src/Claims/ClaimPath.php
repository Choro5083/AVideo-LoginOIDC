<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Claims;

/** ドット区切りのパスで claim を読む（例: resource_access.avideo.roles）。 */
final class ClaimPath
{
    /**
     * パスの先にある値を、文字列の一覧として返す。
     *
     * 値が配列なら文字列の要素を、文字列なら1要素の一覧を返す。パスが無い、
     * または値が文字列でも配列でもなければ、空の一覧を返す。
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
     * パスの先にある文字列を返す。無い、または文字列でなければ空文字。
     *
     * @param array<string, mixed> $claims
     */
    public static function string(array $claims, string $path): string
    {
        $value = self::resolve($claims, $path);

        return is_string($value) ? $value : '';
    }

    /**
     * キー自体がドットを含むことがある（Keycloak の client ID が「my.client」のとき、
     * resource_access.my.client.roles になる）。各階層で、残りのパスの長い方から
     * キーとして試す。
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
