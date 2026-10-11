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

    private static function matches(string $packed, string $range): bool
    {
        [$network, $prefix] = array_pad(explode('/', $range, 2), 2, null);
        $packedNetwork = @inet_pton((string) $network);
        // IPv4 と IPv6 は長さが違うので、種類が違えば一致しない。
        if ($packedNetwork === false || strlen($packedNetwork) !== strlen($packed)) {
            return false;
        }
        $bits = strlen($packed) * 8;
        if ($prefix === null) {
            $prefixLength = $bits;
        } elseif (ctype_digit($prefix) && (int) $prefix <= $bits) {
            $prefixLength = (int) $prefix;
        } else {
            return false;
        }

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
