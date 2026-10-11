<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Native;

final class IpMatcher
{
    /**
     * アドレスが、一覧のどれか（単一のアドレスか CIDR）に含まれるか。
     * 読めない項目は一致しないものとして扱う。
     *
     * @param list<string> $ranges
     */
    public static function matchesAny(string $address, array $ranges): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }
        foreach ($ranges as $range) {
            if (self::matches($packed, $range)) {
                return true;
            }
        }

        return false;
    }

    /** 単一のアドレスか CIDR として読めるか。 */
    public static function isValidRange(string $range): bool
    {
        return self::parse($range) !== null;
    }

    /** すべてのアドレスに一致する範囲か（0.0.0.0/0、::/0）。 */
    public static function coversEverything(string $range): bool
    {
        $parsed = self::parse($range);

        return $parsed !== null && $parsed[1] === 0;
    }

    /** @return array{string, int}|null [ネットワークのアドレス（バイト列）, プレフィックス長]。読めなければ null */
    private static function parse(string $range): ?array
    {
        [$network, $prefix] = array_pad(explode('/', $range, 2), 2, null);
        $packed = @inet_pton((string) $network);
        if ($packed === false) {
            return null;
        }
        $bits = strlen($packed) * 8;
        if ($prefix === null) {
            return [$packed, $bits];
        }

        return ctype_digit($prefix) && (int) $prefix <= $bits ? [$packed, (int) $prefix] : null;
    }

    private static function matches(string $packed, string $range): bool
    {
        $parsed = self::parse($range);
        // IPv4 と IPv6 は長さが違うので、種類が違えば一致しない。
        if ($parsed === null || strlen($parsed[0]) !== strlen($packed)) {
            return false;
        }
        [$packedNetwork, $prefixLength] = $parsed;

        $fullBytes = intdiv($prefixLength, 8);
        if (substr($packed, 0, $fullBytes) !== substr($packedNetwork, 0, $fullBytes)) {
            return false;
        }
        $remaining = $prefixLength % 8;
        if ($remaining === 0) {
            return true;
        }
        $mask = 0xFF << (8 - $remaining) & 0xFF;

        return (ord($packed[$fullBytes]) & $mask) === (ord($packedNetwork[$fullBytes]) & $mask);
    }
}
