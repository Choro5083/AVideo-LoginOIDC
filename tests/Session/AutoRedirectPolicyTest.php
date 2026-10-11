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
        yield 'request from JavaScript' => [self::request(ajax: true)];
        yield 'request asking for JSON' => [self::request(accept: 'application/json')];
        yield 'no Accept header (curl, the Encoder and so on)' => [self::request(accept: '')];
        yield 'request asking for video or images' => [self::request(accept: 'video/*;q=0.9,*/*;q=0.5')];
        yield '*.json.php' => [self::request('/objects/videos.json.php')];
        yield 'sign-in page of AVideo' => [self::request('/view/user.php')];
        yield 'sign-in handler of AVideo' => [self::request('/objects/login.json.php')];
        yield 'logout of AVideo' => [self::request('/objects/logoff.php')];
        yield 'sign-in entry point of the plugin' => [self::request('/plugin/LoginOIDC/login.php')];
        yield 'callback of the plugin' => [self::request('/plugin/LoginOIDC/callback.php')];
        yield 'sign-in page of an AVideo installed in a subdirectory' => [self::request('/avideo/view/user.php')];
        yield 'address of the Encoder' => [self::request(remoteAddress: '192.0.2.10')];
    }

    #[DataProvider('requestsLeftAlone')]
    public function testLeavesOtherRequestsAlone(PageRequest $request): void
    {
        $settings = SettingsFactory::make(['autoRedirect' => true, 'encoderAddresses' => '192.0.2.10']);

        self::assertFalse(AutoRedirectPolicy::shouldRedirect($settings, $request));
    }
}
