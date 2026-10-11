<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Tests;

use LoginOIDC\AVideo\Translations;
use PHPUnit\Framework\TestCase;

/**
 * 翻訳の漏れを見つける。英語の文言がキーなので、コードの文言を直したのに言語のファイルを
 * 直し忘れると、その文言だけ英語のまま出る。気づきにくいので、ここで確かめる。
 */
final class TranslationsTest extends TestCase
{
    private const PLUGIN = __DIR__ . '/../LoginOIDC';

    /** 文字列リテラル（シングルクォート）の中身に一致する */
    private const LITERAL = '\'((?:[^\'\\\\]|\\\\.)*)\'';

    /**
     * コードの中の、翻訳される英語の文言をすべて集める。
     *
     * @return list<string>
     */
    private static function textsInCode(): array
    {
        $patterns = [
            // 翻訳の関数に直接渡している文言
            '/(?:self::t|self::html|LoginOIDC::t|\$text|\b__)\(\s*' . self::LITERAL . '/',
            // 検査結果のひな形（2つ目の引数）
            '/Finding::(?:error|warning)\(\s*\'[^\']*\',\s*' . self::LITERAL . '/',
            // 利用者に見せる失敗の文言（loginOidcFail の3つ目の引数と、その既定値）
            '/loginOidcFail\([^;]*,\s*' . self::LITERAL . '\s*\);/',
            '/string \$userMessage = ' . self::LITERAL . '/',
        ];
        $texts = ['error', 'warning']; // 検査結果の種別。変数で渡しているので、ここに書く

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
            // 設定項目の名前と説明（helpTexts の 'item' => ['name', 'description'] の行）
            preg_match_all('/^\s*\'\w+\' => \[' . self::LITERAL . ', ' . self::LITERAL . '\],$/m', $source, $matches);
            array_push($texts, ...array_map('stripslashes', $matches[1]), ...array_map('stripslashes', $matches[2]));
        }

        // 「%s」は値をそのまま出すだけのひな形で、訳すものが無い。
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

        // 集め方が壊れて「漏れなし」に見えるのを防ぐ。代表的な文言が拾えていることを確かめる。
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
        // 英語と日本語で同じになる語（固有の名前）は、訳を置かなくてよい。
        $sameInBoth = ['Issuer', 'Client ID', 'Client secret', 'RP-Initiated Logout', 'Back-Channel Logout'];

        $missing = array_values(array_diff(self::textsInCode(), array_keys($japanese), $sameInBoth));

        self::assertSame([], $missing, 'locale/ja_JP.php に訳が無い文言');
    }

    public function testJapaneseHasNoStaleEntries(): void
    {
        // コードから消えた文言の訳が残っていないか。'Login with SSO' は設定の既定値で、変数で渡している。
        $stale = array_values(array_diff(array_keys(self::locale('ja_JP')), self::textsInCode(), ['Login with SSO']));

        self::assertSame([], $stale, 'コードに無い文言の訳が locale/ja_JP.php に残っている');
    }

    public function testPlaceholdersMatchBetweenLanguages(): void
    {
        foreach (self::locale('ja_JP') as $english => $japanese) {
            preg_match_all('/%[sd]/', $english, $inEnglish);
            preg_match_all('/%[sd]/', $japanese, $inJapanese);

            // 並びまで同じでなければならない（値は順番に埋められる）。
            self::assertSame($inEnglish[0], $inJapanese[0], "埋め込む値の数か並びが違う: {$english}");
        }
    }

    public function testPicksTheFileForTheLanguage(): void
    {
        $directory = self::PLUGIN . '/locale';

        self::assertSame("{$directory}/ja_JP.php", Translations::fileFor($directory, 'ja_JP'));
        self::assertSame("{$directory}/ja_JP.php", Translations::fileFor($directory, 'ja'), '言語の部分だけでも見つける');
        self::assertNull(Translations::fileFor($directory, 'en_US'), '英語はコードの文言をそのまま使う');
        self::assertNull(Translations::fileFor($directory, 'xx_YY'));
        self::assertNull(Translations::fileFor($directory, ''));
        self::assertNull(Translations::fileFor($directory, '../../etc/passwd'), 'パスとして使える文字は受け付けない');
    }
}
