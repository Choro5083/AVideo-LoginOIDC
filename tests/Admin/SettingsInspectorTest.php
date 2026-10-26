<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Tests\Admin;

use LoginOIDC\Admin\Finding;
use LoginOIDC\Admin\SettingsInspector;
use LoginOIDC\Native\IpMatcher;
use LoginOIDC\Tests\Support\SettingsFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingsInspectorTest extends TestCase
{
    /**
     * A configuration without problems. Each case breaks it in one place.
     */
    private const GOOD = [
        'rolesClaimPath' => 'groups',
        'managedFlags' => 'isAdmin, canUpload',
        'flagMapping' => '{"admins": ["isAdmin"], "uploaders": ["canUpload"]}',
        'groupsClaimPath' => 'groups',
        'managedGroups' => '3',
        'groupMapping' => '{"staff": [3]}',
        'requiredClaimPath' => 'groups',
        'requiredClaimValues' => 'viewers',
        'nativeLoginMode' => 'allowlist',
        'nativeLoginAllowlist' => 'admin',
        'encoderAddresses' => '192.0.2.10',
        'syncEnabled' => true,
        'syncClientId' => 'avideo-sync',
        'syncRoleSource' => 'groups',
    ];

    /**
     * @param  array<string, mixed> $overrides
     * @return list<string> list of "level setting"
     */
    private static function inspect(array $overrides = []): array
    {
        $findings = SettingsInspector::inspect(
            SettingsFactory::make($overrides + self::GOOD),
            static fn (int $groupId): bool => $groupId === 3,
            static fn (string $username): bool => $username === 'admin',
        );

        return array_map(static fn (Finding $f): string => "{$f->level} {$f->setting}", $findings);
    }

    public function testFindsNothingInAGoodConfiguration(): void
    {
        self::assertSame([], self::inspect());
    }

    public function testFreshInstallOnlyReportsWhatIsMissing(): void
    {
        $findings = SettingsInspector::inspect(
            SettingsFactory::make(['issuer' => '', 'clientId' => '', 'clientSecret' => '']),
            static fn (int $id): bool => false,
            static fn (string $name): bool => false,
        );

        self::assertSame(
            ['error issuer', 'error clientId', 'error clientSecret'],
            array_map(static fn (Finding $f): string => "{$f->level} {$f->setting}", $findings),
        );
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function problems(): iterable
    {
        yield 'issuer is not a URL' => [['issuer' => 'idp.example.com'], 'error issuer'];
        yield 'issuer has a query' => [['issuer' => 'https://idp.example.com/?a=1'], 'error issuer'];
        yield 'issuer uses http' => [['issuer' => 'http://idp.example.com'], 'error issuer'];
        yield 'http is allowed' => [['issuer' => 'http://idp.example.com', 'allowInsecureHttp' => true], 'warning allowInsecureHttp'];
        yield 'no openid in the scopes' => [['scopes' => 'profile email'], 'error scopes'];
        yield 'no secret anywhere' => [['clientSecret' => ''], 'error clientSecret'];
        yield 'managed flags without a claim path' => [['rolesClaimPath' => ''], 'warning rolesClaimPath'];
        yield 'a mapping without managed flags' => [['managedFlags' => ''], 'warning managedFlags'];
        yield 'the mapping points at an unmanaged flag' => [
            ['flagMapping' => '{"admins": ["isAdmin"], "uploaders": ["canUpload"], "s": ["canStream"]}'],
            'warning flagMapping',
        ];
        yield 'nothing grants a managed flag (removed from everyone)' => [
            ['flagMapping' => '{"uploaders": ["canUpload"]}'],
            'warning flagMapping',
        ];
        yield 'user group that does not exist' => [['managedGroups' => '3, 99', 'groupMapping' => '{"staff": [3]}'], 'error managedGroups'];
        yield 'the mapping points at an unmanaged group' => [['groupMapping' => '{"staff": [3, 5]}'], 'warning groupMapping'];
        yield 'only one half of the requirement' => [['requiredClaimValues' => ''], 'warning requiredClaimPath'];
        yield 'empty allowlist (no way in)' => [['nativeLoginAllowlist' => ''], 'warning nativeLoginAllowlist'];
        yield 'allowlist names a user that does not exist' => [['nativeLoginAllowlist' => "admin\nghost"], 'warning nativeLoginAllowlist'];
        yield 'unreadable Encoder address' => [['encoderAddresses' => 'encoder.example.com'], 'error encoderAddresses'];
        yield 'Encoder address covers every address' => [['encoderAddresses' => '0.0.0.0/0'], 'error encoderAddresses'];
        yield 'restricted without an Encoder address' => [['encoderAddresses' => ''], 'warning encoderAddresses'];
        yield 'no sync client' => [['syncClientId' => ''], 'error syncClientId'];
        yield 'Admin API URL cannot be derived' => [['issuer' => 'https://accounts.example.com'], 'error syncAdminUrl'];
        yield 'client roles without a client' => [['syncRoleSource' => 'clientRoles'], 'error syncRoleClientId'];
        yield 'client roles need broad permissions' => [
            ['syncRoleSource' => 'clientRoles', 'syncRoleClientId' => 'avideo'],
            'warning syncRoleSource',
        ];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('problems')]
    public function testReportsTheProblem(array $overrides, string $expected): void
    {
        self::assertContains($expected, self::inspect($overrides));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function encoderAddressesAndProxies(): iterable
    {
        yield 'the proxy itself' => ['127.0.0.1', ['127.0.0.1']];
        yield 'a range that contains the proxy' => ["192.0.2.10\n198.51.100.0/24", ['198.51.100.7']];
        yield 'an IPv6 proxy' => ['2001:db8::/32', ['2001:db8::1']];
    }

    /** @param list<string> $proxies */
    #[DataProvider('encoderAddressesAndProxies')]
    public function testReportsAnEncoderAddressThatCoversATrustedProxy(string $encoderAddresses, array $proxies): void
    {
        $findings = SettingsInspector::inspect(
            SettingsFactory::make(['encoderAddresses' => $encoderAddresses] + self::GOOD),
            static fn (int $groupId): bool => $groupId === 3,
            static fn (string $username): bool => $username === 'admin',
            $proxies,
        );

        self::assertContains('error encoderAddresses', array_map(static fn (Finding $f): string => "{$f->level} {$f->setting}", $findings));
    }

    public function testAcceptsAnEncoderAddressBesideATrustedProxy(): void
    {
        $findings = SettingsInspector::inspect(
            SettingsFactory::make(self::GOOD),
            static fn (int $groupId): bool => $groupId === 3,
            static fn (string $username): bool => $username === 'admin',
            ['127.0.0.1', '192.0.2.1'],
        );

        self::assertSame([], $findings);
    }

    public function testSyncSettingsAreIgnoredWhenSyncIsOff(): void
    {
        self::assertSame([], self::inspect(['syncEnabled' => false, 'syncClientId' => '', 'syncRoleSource' => 'clientRoles']));
    }

    public function testAllowlistIsIgnoredInOtherModes(): void
    {
        self::assertSame([], self::inspect(['nativeLoginMode' => 'allow', 'nativeLoginAllowlist' => 'ghost', 'encoderAddresses' => '']));
    }

    public function testExplicitAdminUrlSatisfiesSync(): void
    {
        self::assertNotContains(
            'error syncAdminUrl',
            self::inspect(['issuer' => 'https://accounts.example.com', 'syncAdminUrl' => 'https://accounts.example.com/admin/realms/x']),
        );
    }

    public function testRangeValidation(): void
    {
        self::assertTrue(IpMatcher::isValidRange('192.0.2.10'));
        self::assertTrue(IpMatcher::isValidRange('2001:db8::/32'));
        self::assertFalse(IpMatcher::isValidRange('192.0.2.10/33'));
        self::assertFalse(IpMatcher::isValidRange('host.example.com'));
        self::assertTrue(IpMatcher::coversEverything('::/0'));
        self::assertFalse(IpMatcher::coversEverything('10.0.0.0/8'));
    }
}
