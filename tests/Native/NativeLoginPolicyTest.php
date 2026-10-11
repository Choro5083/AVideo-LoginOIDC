<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Tests\Native;

use LoginOIDC\Native\IpMatcher;
use LoginOIDC\Native\NativeLoginAttempt;
use LoginOIDC\Native\NativeLoginPolicy;
use LoginOIDC\Tests\Support\SettingsFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeLoginPolicyTest extends TestCase
{
    private static function attempt(
        string $username = 'bob',
        bool $linked = true,
        bool $viaCookie = false,
        string $remoteAddress = '203.0.113.5',
    ): NativeLoginAttempt {
        return new NativeLoginAttempt($username, $linked, $viaCookie, $remoteAddress);
    }

    /** @param array<string, mixed> $settings */
    private static function denied(array $settings, NativeLoginAttempt $attempt): bool
    {
        return NativeLoginPolicy::reasonToDeny(SettingsFactory::make($settings), $attempt) !== null;
    }

    public function testAllowModePermitsPasswordLoginForEveryone(): void
    {
        self::assertFalse(self::denied(['nativeLoginMode' => 'allow'], self::attempt(linked: true)));
        self::assertFalse(self::denied(['nativeLoginMode' => 'allow'], self::attempt(linked: false)));
    }

    public function testDenyLinkedModeBlocksOnlyLinkedUsers(): void
    {
        self::assertTrue(self::denied(['nativeLoginMode' => 'denyLinked'], self::attempt(linked: true)));
        self::assertFalse(self::denied(['nativeLoginMode' => 'denyLinked'], self::attempt('admin', linked: false)));
    }

    public function testAllowlistModePermitsOnlyListedUsers(): void
    {
        $settings = ['nativeLoginMode' => 'allowlist', 'nativeLoginAllowlist' => "admin\noperator"];

        self::assertFalse(self::denied($settings, self::attempt('admin', linked: false)));
        self::assertTrue(self::denied($settings, self::attempt('someone', linked: false)), '紐付いていなくても、一覧に無ければ拒否');
        self::assertTrue(self::denied($settings, self::attempt('bob', linked: true)));
        self::assertTrue(self::denied($settings, self::attempt('Admin', linked: false)), '大文字と小文字は区別する');
    }

    public function testEmptyAllowlistDeniesEveryone(): void
    {
        self::assertTrue(self::denied(['nativeLoginMode' => 'allowlist'], self::attempt('admin', linked: false)));
    }

    public function testCookieRestorationIsNeverAllowedForLinkedUsers(): void
    {
        // どのモードでも、IdP に紐付いたユーザは Cookie からログインを復元させない
        foreach (['allow', 'denyLinked'] as $mode) {
            self::assertTrue(self::denied(['nativeLoginMode' => $mode], self::attempt(linked: true, viaCookie: true)), $mode);
        }
        self::assertFalse(
            self::denied(['nativeLoginMode' => 'allow'], self::attempt('admin', linked: false, viaCookie: true)),
            '紐付いていないユーザの Cookie による復元は、本体の挙動のまま',
        );
    }

    public function testEncoderAddressBypassesEveryRule(): void
    {
        $settings = [
            'nativeLoginMode' => 'allowlist',
            'encoderAddresses' => "192.0.2.10\n2001:db8:1::/64",
        ];

        self::assertFalse(self::denied($settings, self::attempt(linked: true, remoteAddress: '192.0.2.10')));
        self::assertFalse(self::denied($settings, self::attempt(linked: true, viaCookie: true, remoteAddress: '2001:db8:1::25')));
        self::assertTrue(self::denied($settings, self::attempt(linked: true, remoteAddress: '192.0.2.11')));
    }

    /** @return iterable<string, array{string, list<string>, bool}> */
    public static function addresses(): iterable
    {
        yield '単一の IPv4' => ['192.0.2.10', ['192.0.2.10'], true];
        yield '別の IPv4' => ['192.0.2.11', ['192.0.2.10'], false];
        yield 'IPv4 の CIDR の中' => ['198.51.100.200', ['198.51.100.0/24'], true];
        yield 'IPv4 の CIDR の外' => ['198.51.101.1', ['198.51.100.0/24'], false];
        yield 'バイト境界でない CIDR の中' => ['10.0.3.255', ['10.0.0.0/22'], true];
        yield 'バイト境界でない CIDR の外' => ['10.0.4.0', ['10.0.0.0/22'], false];
        yield '/32' => ['10.0.0.1', ['10.0.0.1/32'], true];
        yield '/0 はすべて' => ['203.0.113.9', ['0.0.0.0/0'], true];
        yield 'IPv6 の CIDR の中' => ['2001:db8:1::25', ['2001:db8:1::/64'], true];
        yield 'IPv6 の CIDR の外' => ['2001:db8:2::25', ['2001:db8:1::/64'], false];
        yield 'IPv4 と IPv6 は混ざらない' => ['192.0.2.10', ['::/0'], false];
        yield '一覧の2つ目に一致' => ['192.0.2.10', ['198.51.100.0/24', '192.0.2.0/24'], true];
        yield '空の一覧' => ['192.0.2.10', [], false];
        yield '読めない項目は無視' => ['192.0.2.10', ['not-an-ip', '192.0.2.10/99', '192.0.2.10/x'], false];
        yield '読めないアドレス' => ['', ['0.0.0.0/0'], false];
    }

    /** @param list<string> $ranges */
    #[DataProvider('addresses')]
    public function testIpMatcher(string $address, array $ranges, bool $expected): void
    {
        self::assertSame($expected, IpMatcher::matchesAny($address, $ranges));
    }
}
