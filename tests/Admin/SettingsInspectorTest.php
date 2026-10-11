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
     * 問題のない設定。ここから1か所ずつ崩して確かめる。
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
     * @return list<string> 「level setting」の一覧
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
        yield 'issuer が URL でない' => [['issuer' => 'idp.example.com'], 'error issuer'];
        yield 'issuer にクエリがある' => [['issuer' => 'https://idp.example.com/?a=1'], 'error issuer'];
        yield 'issuer が http' => [['issuer' => 'http://idp.example.com'], 'error issuer'];
        yield 'http を許している' => [['issuer' => 'http://idp.example.com', 'allowInsecureHttp' => true], 'warning allowInsecureHttp'];
        yield 'scope に openid が無い' => [['scopes' => 'profile email'], 'error scopes'];
        yield 'シークレットがどこにも無い' => [['clientSecret' => ''], 'error clientSecret'];
        yield '管理するフラグがあるのに claim のパスが無い' => [['rolesClaimPath' => ''], 'warning rolesClaimPath'];
        yield '対応表があるのに管理するフラグが無い' => [['managedFlags' => ''], 'warning managedFlags'];
        yield '対応表が管理対象でないフラグを指す' => [
            ['flagMapping' => '{"admins": ["isAdmin"], "uploaders": ["canUpload"], "s": ["canStream"]}'],
            'warning flagMapping',
        ];
        yield '管理するフラグに付ける条件が無い（全員から外れる）' => [
            ['flagMapping' => '{"uploaders": ["canUpload"]}'],
            'warning flagMapping',
        ];
        yield '存在しない user group' => [['managedGroups' => '3, 99', 'groupMapping' => '{"staff": [3]}'], 'error managedGroups'];
        yield '対応表が管理対象でない group を指す' => [['groupMapping' => '{"staff": [3, 5]}'], 'warning groupMapping'];
        yield '必要な条件の片方だけ' => [['requiredClaimValues' => ''], 'warning requiredClaimPath'];
        yield '許可リストが空（入口が無くなる）' => [['nativeLoginAllowlist' => ''], 'warning nativeLoginAllowlist'];
        yield '許可リストに存在しないユーザ' => [['nativeLoginAllowlist' => "admin\nghost"], 'warning nativeLoginAllowlist'];
        yield 'Encoder のアドレスが読めない' => [['encoderAddresses' => 'encoder.example.com'], 'error encoderAddresses'];
        yield 'Encoder のアドレスが全アドレス' => [['encoderAddresses' => '0.0.0.0/0'], 'error encoderAddresses'];
        yield '制限しているのに Encoder のアドレスが無い' => [['encoderAddresses' => ''], 'warning encoderAddresses'];
        yield '同期の client が無い' => [['syncClientId' => ''], 'error syncClientId'];
        yield 'Admin API の URL を導けない' => [['issuer' => 'https://accounts.example.com'], 'error syncAdminUrl'];
        yield 'client role を読むのに client が無い' => [['syncRoleSource' => 'clientRoles'], 'error syncRoleClientId'];
        yield 'client role は権限が広い' => [
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
