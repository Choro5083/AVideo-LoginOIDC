<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Admin;

/** 設定の検査で見つかったこと。 */
final class Finding
{
    public const ERROR = 'error';
    public const WARNING = 'warning';

    /** @param list<string|int> $arguments */
    private function __construct(
        public readonly string $level,
        /** 関係する設定項目の名前 */
        public readonly string $setting,
        /** 説明のひな形（sprintf の形式）。翻訳のキーになるので、値は埋め込まずに $arguments で渡す */
        public readonly string $template,
        public readonly array $arguments,
    ) {
    }

    /** このままでは動かない、または意図と違う動きになる。 */
    public static function error(string $setting, string $template, string|int ...$arguments): self
    {
        return new self(self::ERROR, $setting, $template, array_values($arguments));
    }

    /** 動くが、確かめた方がよい。 */
    public static function warning(string $setting, string $template, string|int ...$arguments): self
    {
        return new self(self::WARNING, $setting, $template, array_values($arguments));
    }

    /**
     * 説明の文を作る。
     *
     * @param (callable(string): string)|null $translate ひな形を翻訳する。省略すると英語のまま
     */
    public function message(?callable $translate = null): string
    {
        return vsprintf($translate === null ? $this->template : $translate($this->template), $this->arguments);
    }
}
