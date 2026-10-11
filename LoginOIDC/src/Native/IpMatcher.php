<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Native;

final class IpMatcher
{
    /**
     * Whether the address is covered by any item of the list (a single address or a CIDR range).
     * Items that cannot be read match nothing.
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

    /** Whether it reads as a single address or a CIDR range. */
    public static function isValidRange(string $range): bool
    {
        return self::parse($range) !== null;
    }

    /** Whether the range matches every address (0.0.0.0/0, ::/0). */
    public static function coversEverything(string $range): bool
    {
        $parsed = self::parse($range);

        return $parsed !== null && $parsed[1] === 0;
    }

    /** @return array{string, int}|null [network address as bytes, prefix length]. null when it cannot be read */
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
        // IPv4 and IPv6 differ in length, so different families never match.
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
