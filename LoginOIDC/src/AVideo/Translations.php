<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\AVideo;

/**
 * プラグインの文言を、本体の翻訳の仕組みに足す。
 *
 * 本体は、英語の文言をキーにして、言語ごとの配列（グローバルの $t）で置き換える（__() 関数）。
 * 言語のファイルは本体の locale/ にしか置けないので、プラグインの分は、ここで $t に足す。
 * 英語は、コードに書いた文言がそのまま出る。
 */
final class Translations
{
    /** @param string $directory 言語ごとのファイル（例: ja_JP.php）を置いたディレクトリ */
    public static function register(string $directory): void
    {
        global $t;

        $file = self::fileFor($directory, (string) ($_SESSION['language'] ?? ''));
        if ($file === null) {
            return;
        }
        $texts = require $file;
        if (is_array($texts)) {
            // 本体の文言は上書きしない。同じキーがあれば、本体の訳を使う。
            $t = (is_array($t) ? $t : []) + $texts;
        }
    }

    /**
     * 言語のコードに合うファイルを探す。完全に一致するものが無ければ、言語の部分が同じものを使う
     * （「ja」に対して ja_JP.php）。
     */
    public static function fileFor(string $directory, string $language): ?string
    {
        // 言語のコードは、セッションや URL から来る。ファイル名に使う前に、形を確かめる。
        if (preg_match('/^[A-Za-z]{2,3}(_[A-Za-z0-9]+)*$/', $language) !== 1) {
            return null;
        }
        $exact = "{$directory}/{$language}.php";
        if (is_file($exact)) {
            return $exact;
        }
        $base = explode('_', $language)[0];
        foreach ([...(glob("{$directory}/{$base}.php") ?: []), ...(glob("{$directory}/{$base}_*.php") ?: [])] as $candidate) {
            return $candidate;
        }

        return null;
    }
}
