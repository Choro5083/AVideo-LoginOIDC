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
        self::assertTrue(self::denied($settings, self::attempt('someone', linked: false)), 'denied when not in the list, linked or not');
        self::assertTrue(self::denied($settings, self::attempt('bob', linked: true)));
        self::assertTrue(self::denied($settings, self::attempt('Admin', linked: false)), 'case-sensitive');
    }

    public function testEmptyAllowlistDeniesEveryone(): void
    {
        self::assertTrue(self::denied(['nativeLoginMode' => 'allowlist'], self::attempt('admin', linked: false)));
    }

    public function testCookieRestorationIsNeverAllowedForLinkedUsers(): void
    {
        // in every mode, a user linked to the provider is never restored from the cookie
        foreach (['allow', 'denyLinked'] as $mode) {
            self::assertTrue(self::denied(['nativeLoginMode' => $mode], self::attempt(linked: true, viaCookie: true)), $mode);
        }
        self::assertFalse(
            self::denied(['nativeLoginMode' => 'allow'], self::attempt('admin', linked: false, viaCookie: true)),
            'restoring an unlinked user from the cookie stays as AVideo does it',
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
        yield 'single IPv4 address' => ['192.0.2.10', ['192.0.2.10'], true];
        yield 'another IPv4 address' => ['192.0.2.11', ['192.0.2.10'], false];
        yield 'inside an IPv4 CIDR range' => ['198.51.100.200', ['198.51.100.0/24'], true];
        yield 'outside an IPv4 CIDR range' => ['198.51.101.1', ['198.51.100.0/24'], false];
        yield 'inside a CIDR range not on a byte boundary' => ['10.0.3.255', ['10.0.0.0/22'], true];
        yield 'outside a CIDR range not on a byte boundary' => ['10.0.4.0', ['10.0.0.0/22'], false];
        yield '/32' => ['10.0.0.1', ['10.0.0.1/32'], true];
        yield '/0 covers everything' => ['203.0.113.9', ['0.0.0.0/0'], true];
        yield 'inside an IPv6 CIDR range' => ['2001:db8:1::25', ['2001:db8:1::/64'], true];
        yield 'outside an IPv6 CIDR range' => ['2001:db8:2::25', ['2001:db8:1::/64'], false];
        yield 'IPv4 and IPv6 never match each other' => ['192.0.2.10', ['::/0'], false];
        yield 'matches the second item of the list' => ['192.0.2.10', ['198.51.100.0/24', '192.0.2.0/24'], true];
        yield 'empty list' => ['192.0.2.10', [], false];
        yield 'unreadable items are ignored' => ['192.0.2.10', ['not-an-ip', '192.0.2.10/99', '192.0.2.10/x'], false];
        yield 'unreadable address' => ['', ['0.0.0.0/0'], false];
    }

    /** @param list<string> $ranges */
    #[DataProvider('addresses')]
    public function testIpMatcher(string $address, array $ranges, bool $expected): void
    {
        self::assertSame($expected, IpMatcher::matchesAny($address, $ranges));
    }
}
