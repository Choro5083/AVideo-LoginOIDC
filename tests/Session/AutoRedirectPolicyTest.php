<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Tests\Session;

use LoginOIDC\Session\AutoRedirectPolicy;
use LoginOIDC\Session\PageRequest;
use LoginOIDC\Tests\Support\SettingsFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AutoRedirectPolicyTest extends TestCase
{
    private const BROWSER_ACCEPT = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';

    private static function request(
        string $scriptName = '/view/index.php',
        string $method = 'GET',
        string $accept = self::BROWSER_ACCEPT,
        bool $ajax = false,
        string $remoteAddress = '203.0.113.5',
    ): PageRequest {
        return new PageRequest($method, $scriptName, $accept, $ajax, $remoteAddress);
    }

    public function testRedirectsBrowserPageViewsWhenEnabled(): void
    {
        $settings = SettingsFactory::make(['autoRedirect' => true]);

        self::assertTrue(AutoRedirectPolicy::shouldRedirect($settings, self::request()));
        self::assertTrue(AutoRedirectPolicy::shouldRedirect($settings, self::request('/view/modeYoutube.php')));
    }

    public function testDoesNothingWhenDisabledOrNotConfigured(): void
    {
        self::assertFalse(AutoRedirectPolicy::shouldRedirect(SettingsFactory::make(), self::request()));
        self::assertFalse(AutoRedirectPolicy::shouldRedirect(
            SettingsFactory::make(['autoRedirect' => true, 'issuer' => '']),
            self::request(),
        ));
    }

    /** @return iterable<string, array{PageRequest}> */
    public static function requestsLeftAlone(): iterable
    {
        yield 'POST' => [self::request(method: 'POST')];
        yield 'JavaScript からの通信' => [self::request(ajax: true)];
        yield 'JSON を求めるリクエスト' => [self::request(accept: 'application/json')];
        yield 'Accept が無い（curl、Encoder など）' => [self::request(accept: '')];
        yield '動画や画像を求めるリクエスト' => [self::request(accept: 'video/*;q=0.9,*/*;q=0.5')];
        yield '*.json.php' => [self::request('/objects/videos.json.php')];
        yield '本体のログイン画面' => [self::request('/view/user.php')];
        yield '本体のログイン処理' => [self::request('/objects/login.json.php')];
        yield '本体のログアウト' => [self::request('/objects/logoff.php')];
        yield 'プラグインのログインの入口' => [self::request('/plugin/LoginOIDC/login.php')];
        yield 'プラグインのコールバック' => [self::request('/plugin/LoginOIDC/callback.php')];
        yield 'サブディレクトリに置いた AVideo のログイン画面' => [self::request('/avideo/view/user.php')];
        yield 'Encoder のアドレス' => [self::request(remoteAddress: '192.0.2.10')];
    }

    #[DataProvider('requestsLeftAlone')]
    public function testLeavesOtherRequestsAlone(PageRequest $request): void
    {
        $settings = SettingsFactory::make(['autoRedirect' => true, 'encoderAddresses' => '192.0.2.10']);

        self::assertFalse(AutoRedirectPolicy::shouldRedirect($settings, $request));
    }
}
