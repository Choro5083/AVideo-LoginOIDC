<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\User;

/** Makes an AVideo username from a claim value. */
final class UsernamePolicy
{
    /** users.user is VARCHAR(45) */
    public const MAX_LENGTH = 45;

    private const FALLBACK = 'user';

    /**
     * Returns a candidate with unusable characters removed and the length cut.
     *
     * Only letters, digits and . _ - @ are kept. AVideo puts the username into URLs
     * and HTML as it is in places, so symbols are not allowed broadly.
     */
    public static function sanitize(string $raw): string
    {
        $cleaned = preg_replace('/[^A-Za-z0-9._@-]+/', '', $raw) ?? '';
        $cleaned = trim(substr($cleaned, 0, self::MAX_LENGTH), '.-_@');

        return $cleaned === '' ? self::FALLBACK : $cleaned;
    }

    /**
     * Returns a username that is not taken. On a clash a number is appended (alice, alice2, alice3…).
     *
     * @param callable(string): bool $isTaken
     * @throws \RuntimeException when no free name is found
     */
    public static function unique(string $raw, callable $isTaken): string
    {
        $base = self::sanitize($raw);
        if (!$isTaken($base)) {
            return $base;
        }
        for ($n = 2; $n <= 9999; $n++) {
            $suffix = (string) $n;
            $candidate = substr($base, 0, self::MAX_LENGTH - strlen($suffix)) . $suffix;
            if (!$isTaken($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException("no free username derived from \"{$base}\"");
    }
}
