<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Tests;

use LoginOIDC\AVideo\Translations;
use PHPUnit\Framework\TestCase;

/**
 * Finds missing translations. The English text is the key, so changing a text in the code
 * without updating the language file leaves just that text in English. That is easy to miss, so it is checked here.
 */
final class TranslationsTest extends TestCase
{
    private const PLUGIN = __DIR__ . '/../LoginOIDC';

    /** matches the content of a single-quoted string literal */
    private const LITERAL = '\'((?:[^\'\\\\]|\\\\.)*)\'';

    /**
     * Collects every English text in the code that gets translated.
     *
     * @return list<string>
     */
    private static function textsInCode(): array
    {
        $patterns = [
            // texts passed straight to a translation function
            '/(?:self::t|self::html|LoginOIDC::t|\$text|\b__)\(\s*' . self::LITERAL . '/',
            // templates of findings (the second argument)
            '/Finding::(?:error|warning)\(\s*\'[^\']*\',\s*' . self::LITERAL . '/',
            // failure texts shown to the user (third argument of loginOidcFail, and its default)
            '/loginOidcFail\([^;]*,\s*' . self::LITERAL . '\s*\);/',
            '/string \$userMessage = ' . self::LITERAL . '/',
        ];
        $texts = ['error', 'warning']; // levels of findings. They are passed in a variable, so they are listed here

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::PLUGIN, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/locale/')) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $source, $matches);
                array_push($texts, ...array_map('stripslashes', $matches[1]));
            }
            // names and descriptions of settings (the 'item' => ['name', 'description'] lines of helpTexts)
            preg_match_all('/^\s*\'\w+\' => \[' . self::LITERAL . ', ' . self::LITERAL . '\],$/m', $source, $matches);
            array_push($texts, ...array_map('stripslashes', $matches[1]), ...array_map('stripslashes', $matches[2]));
        }

        // "%s" is a template that only prints a value. There is nothing to translate.
        return array_values(array_unique(array_filter($texts, static fn (string $t): bool => $t !== '' && $t !== '%s')));
    }

    /** @return array<string, string> */
    private static function locale(string $name): array
    {
        return require self::PLUGIN . "/locale/{$name}.php";
    }

    public function testFindsTheTextsInTheCode(): void
    {
        $texts = self::textsInCode();

        // Guards against a broken collector that makes everything look covered: some known texts must be found.
        self::assertContains('Issuer', $texts);
        self::assertContains('Check settings', $texts);
        self::assertContains('User group %s does not exist.', $texts);
        self::assertContains('Your account is not allowed to sign in here.', $texts);
        self::assertContains('Sign-in failed. Please try again.', $texts);
        self::assertGreaterThan(100, count($texts));
    }

    public function testJapaneseCoversEveryTextInTheCode(): void
    {
        $japanese = self::locale('ja_JP');
        // Terms that are the same in English and Japanese (proper names) need no entry.
        $sameInBoth = ['Issuer', 'Client ID', 'Client secret', 'RP-Initiated Logout', 'Back-Channel Logout'];

        $missing = array_values(array_diff(self::textsInCode(), array_keys($japanese), $sameInBoth));

        self::assertSame([], $missing, 'texts without a translation in locale/ja_JP.php');
    }

    public function testJapaneseHasNoStaleEntries(): void
    {
        // No translations left over for texts that are gone from the code. 'Login with SSO' is a setting default, passed in a variable.
        $stale = array_values(array_diff(array_keys(self::locale('ja_JP')), self::textsInCode(), ['Login with SSO']));

        self::assertSame([], $stale, 'locale/ja_JP.php has translations for texts that are not in the code');
    }

    public function testPlaceholdersMatchBetweenLanguages(): void
    {
        foreach (self::locale('ja_JP') as $english => $japanese) {
            preg_match_all('/%[sd]/', $english, $inEnglish);
            preg_match_all('/%[sd]/', $japanese, $inJapanese);

            // Even the order must match (the values are filled in order).
            self::assertSame($inEnglish[0], $inJapanese[0], "number or order of placeholders differs: {$english}");
        }
    }

    public function testPicksTheFileForTheLanguage(): void
    {
        $directory = self::PLUGIN . '/locale';

        self::assertSame("{$directory}/ja_JP.php", Translations::fileFor($directory, 'ja_JP'));
        self::assertSame("{$directory}/ja_JP.php", Translations::fileFor($directory, 'ja'), 'the language part alone is enough');
        self::assertNull(Translations::fileFor($directory, 'en_US'), 'English uses the texts in the code as they are');
        self::assertNull(Translations::fileFor($directory, 'xx_YY'));
        self::assertNull(Translations::fileFor($directory, ''));
        self::assertNull(Translations::fileFor($directory, '../../etc/passwd'), 'characters usable in a path are refused');
    }
}
