<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Admin;

/** Something the settings check found. */
final class Finding
{
    public const ERROR = 'error';
    public const WARNING = 'warning';

    /** @param list<string|int> $arguments */
    private function __construct(
        public readonly string $level,
        /** name of the setting it is about */
        public readonly string $setting,
        /** explanation as an sprintf template. It is the translation key, so values go in $arguments, never into the text */
        public readonly string $template,
        public readonly array $arguments,
    ) {
    }

    /** It will not work, or will not do what was intended. */
    public static function error(string $setting, string $template, string|int ...$arguments): self
    {
        return new self(self::ERROR, $setting, $template, array_values($arguments));
    }

    /** It works, but is worth a second look. */
    public static function warning(string $setting, string $template, string|int ...$arguments): self
    {
        return new self(self::WARNING, $setting, $template, array_values($arguments));
    }

    /**
     * Builds the explanation.
     *
     * @param (callable(string): string)|null $translate translates the template. English when omitted
     */
    public function message(?callable $translate = null): string
    {
        return vsprintf($translate === null ? $this->template : $translate($this->template), $this->arguments);
    }
}
