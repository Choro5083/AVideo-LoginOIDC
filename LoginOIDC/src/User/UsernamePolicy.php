<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\User;

/** claim の値から、AVideo の username を作る。 */
final class UsernamePolicy
{
    /** users.user は VARCHAR(45) */
    public const MAX_LENGTH = 45;

    private const FALLBACK = 'user';

    /**
     * 使えない文字を除き、長さを切り詰めた候補を返す。
     *
     * 残すのは英数字と . _ - @ だけ。AVideo は username を URL や HTML に
     * そのまま出す箇所があるため、記号は広く許さない。
     */
    public static function sanitize(string $raw): string
    {
        $cleaned = preg_replace('/[^A-Za-z0-9._@-]+/', '', $raw) ?? '';
        $cleaned = trim(substr($cleaned, 0, self::MAX_LENGTH), '.-_@');

        return $cleaned === '' ? self::FALLBACK : $cleaned;
    }

    /**
     * 使われていない username を返す。重複したら連番を付ける（alice、alice2、alice3…）。
     *
     * @param callable(string): bool $isTaken
     * @throws \RuntimeException 空きが見つからないとき
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
