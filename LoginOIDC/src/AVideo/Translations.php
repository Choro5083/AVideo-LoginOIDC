<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\AVideo;

/**
 * Adds the plugin's texts to AVideo's translation mechanism.
 *
 * AVideo uses the English text as the key and replaces it from a per-language array (the global $t) in __().
 * Language files can only live in AVideo's own locale/, so the plugin's texts are added to $t here.
 * English needs no file: the texts in the code are shown as they are.
 */
final class Translations
{
    /** @param string $directory directory that holds one file per language (for example ja_JP.php) */
    public static function register(string $directory): void
    {
        global $t;

        $file = self::fileFor($directory, (string) ($_SESSION['language'] ?? ''));
        if ($file === null) {
            return;
        }
        $texts = require $file;
        if (is_array($texts)) {
            // Never override AVideo's own texts. If a key exists already, AVideo's translation wins.
            $t = (is_array($t) ? $t : []) + $texts;
        }
    }

    /**
     * Finds the file for a language code. Without an exact match, a file for the same language is used
     * (ja_JP.php for "ja").
     */
    public static function fileFor(string $directory, string $language): ?string
    {
        // The language code comes from the session or the URL. Check its shape before using it in a file name.
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
