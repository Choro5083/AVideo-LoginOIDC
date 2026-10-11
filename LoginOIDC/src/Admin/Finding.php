<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Admin;

/** 設定の検査で見つかったこと。 */
final class Finding
{
    public const ERROR = 'error';
    public const WARNING = 'warning';

    private function __construct(
        public readonly string $level,
        /** 関係する設定項目の名前 */
        public readonly string $setting,
        public readonly string $message,
    ) {
    }

    /** このままでは動かない、または意図と違う動きになる。 */
    public static function error(string $setting, string $message): self
    {
        return new self(self::ERROR, $setting, $message);
    }

    /** 動くが、確かめた方がよい。 */
    public static function warning(string $setting, string $message): self
    {
        return new self(self::WARNING, $setting, $message);
    }
}
