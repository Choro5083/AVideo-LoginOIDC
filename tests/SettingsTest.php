<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Tests;

use LoginOIDC\ClientAuthMethod;
use LoginOIDC\InvalidSettingException;
use LoginOIDC\NativeLoginMode;
use LoginOIDC\Settings;
use LoginOIDC\SyncRoleSource;
use LoginOIDC\UserFlag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    /**
     * 既定値に相当するデータオブジェクト。選択肢と複数行テキストは、
     * AVideo が保存する形（{type, value}）にしてある。
     *
     * @param array<string, mixed> $overrides
     */
    private static function data(array $overrides = []): object
    {
        $wrap = static fn (mixed $value): object => (object) ['type' => 'textarea', 'value' => $value];

        return (object) array_merge([
            'issuer' => 'https://idp.example.com/realms/test/',
            'clientId' => 'avideo',
            'clientSecret' => 'secret',
            'clientSecretFile' => '',
            'clientAuthMethod' => $wrap('client_secret_basic'),
            'scopes' => 'openid profile email',
            'useUserInfo' => false,
            'caCertFile' => '',
            'clockSkewSeconds' => 60,
            'allowInsecureHttp' => false,
            'buttonLabel' => 'Login with SSO',
            'autoRedirect' => false,
            'createUsers' => true,
            'usernameClaim' => 'preferred_username',
            'nameClaim' => 'name',
            'emailClaim' => 'email',
            'updateName' => true,
            'updateEmail' => true,
            'updateEmailVerified' => true,
            'rolesClaimPath' => '',
            'managedFlags' => '',
            'flagMapping' => $wrap('{}'),
            'requiredClaimPath' => '',
            'requiredClaimValues' => '',
            'groupsClaimPath' => '',
            'managedGroups' => '',
            'groupMapping' => $wrap('{}'),
            'rpInitiatedLogout' => true,
            'backchannelLogout' => true,
            'nativeLoginMode' => $wrap('allow'),
            'nativeLoginAllowlist' => $wrap(''),
            'encoderAddresses' => $wrap(''),
            'syncEnabled' => false,
            'syncAdminUrl' => '',
            'syncClientId' => '',
            'syncClientSecretFile' => '',
            'syncRoleSource' => $wrap('clientRoles'),
            'syncRoleClientId' => '',
            'syncMaxDisableRatio' => 20,
            'debugLog' => false,
        ], $overrides);
    }

    public function testReadsDefaults(): void
    {
        $settings = Settings::fromDataObject(self::data());

        self::assertSame('https://idp.example.com/realms/test', $settings->issuer, '末尾のスラッシュは除く');
        self::assertSame(ClientAuthMethod::SecretBasic, $settings->clientAuthMethod);
        self::assertSame(['openid', 'profile', 'email'], $settings->scopes);
        self::assertSame(60, $settings->clockSkewSeconds);
        self::assertSame([], $settings->managedFlags);
        self::assertSame([], $settings->flagMapping);
        self::assertSame(NativeLoginMode::Allow, $settings->nativeLoginMode);
        self::assertSame(SyncRoleSource::ClientRoles, $settings->syncRoleSource);
        self::assertTrue($settings->isConfigured());
    }

    public function testIsNotConfiguredWithoutIssuerOrClientId(): void
    {
        self::assertFalse(Settings::fromDataObject(self::data(['issuer' => '']))->isConfigured());
        self::assertFalse(Settings::fromDataObject(self::data(['clientId' => ' ']))->isConfigured());
    }

    public function testParsesFlagsAndMappings(): void
    {
        $settings = Settings::fromDataObject(self::data([
            'managedFlags' => 'isAdmin, canUpload',
            'flagMapping' => (object) ['type' => 'textarea', 'value' => '{"admin": ["isAdmin"], "uploader": ["canUpload", "canStream"]}'],
            'managedGroups' => "3\n5",
            'groupMapping' => (object) ['type' => 'textarea', 'value' => '{"staff": [3, "5"]}'],
            'requiredClaimValues' => 'viewer,admin',
        ]));

        self::assertSame([UserFlag::IsAdmin, UserFlag::CanUpload], $settings->managedFlags);
        self::assertSame(
            ['admin' => [UserFlag::IsAdmin], 'uploader' => [UserFlag::CanUpload, UserFlag::CanStream]],
            $settings->flagMapping,
        );
        self::assertSame([3, 5], $settings->managedGroups);
        self::assertSame(['staff' => [3, 5]], $settings->groupMapping);
        self::assertSame(['viewer', 'admin'], $settings->requiredClaimValues);
    }

    public function testParsesMultilineLists(): void
    {
        $settings = Settings::fromDataObject(self::data([
            'nativeLoginAllowlist' => (object) ['type' => 'textarea', 'value' => "admin\r\n  operator \n\n"],
            'encoderAddresses' => (object) ['type' => 'textarea', 'value' => "192.0.2.10\n198.51.100.0/24"],
        ]));

        self::assertSame(['admin', 'operator'], $settings->nativeLoginAllowlist);
        self::assertSame(['192.0.2.10', '198.51.100.0/24'], $settings->encoderAddresses);
    }

    public function testAcceptsNumbersSavedAsStrings(): void
    {
        $settings = Settings::fromDataObject(self::data(['clockSkewSeconds' => '120', 'syncMaxDisableRatio' => '0']));

        self::assertSame(120, $settings->clockSkewSeconds);
        self::assertSame(0, $settings->syncMaxDisableRatio);
    }

    public function testClientSecretFileTakesPrecedence(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'loginoidc');
        self::assertIsString($file);
        file_put_contents($file, "from-file\n");
        try {
            $settings = Settings::fromDataObject(self::data(['clientSecret' => 'inline', 'clientSecretFile' => $file]));

            self::assertSame('from-file', $settings->resolveClientSecret(), '前後の改行は除く');
        } finally {
            unlink($file);
        }
        self::assertSame('inline', Settings::fromDataObject(self::data(['clientSecret' => 'inline']))->resolveClientSecret());
    }

    public function testClientSecretMustBeAvailable(): void
    {
        try {
            Settings::fromDataObject(self::data(['clientSecret' => '']))->resolveClientSecret();
            self::fail('シークレットが無いのに通った');
        } catch (InvalidSettingException $e) {
            self::assertStringContainsString('clientSecret:', $e->getMessage());
        }

        $this->expectException(InvalidSettingException::class);
        $this->expectExceptionMessage('clientSecretFile:');

        Settings::fromDataObject(self::data(['clientSecretFile' => '/nonexistent/secret']))->resolveClientSecret();
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidSettings(): iterable
    {
        $wrap = static fn (mixed $value): object => (object) ['type' => 'textarea', 'value' => $value];

        yield '知らないフラグ' => [['managedFlags' => 'isAdmin, isRoot'], 'managedFlags'];
        yield '壊れた JSON' => [['flagMapping' => $wrap('{"admin": ')], 'flagMapping'];
        yield '対応表が配列' => [['flagMapping' => $wrap('["isAdmin"]')], 'flagMapping'];
        yield '対応先が配列でない' => [['flagMapping' => $wrap('{"admin": "isAdmin"}')], 'flagMapping.admin'];
        yield '対応先に知らないフラグ' => [['flagMapping' => $wrap('{"admin": ["root"]}')], 'flagMapping.admin'];
        yield 'group の ID が数値でない' => [['groupMapping' => $wrap('{"staff": ["editors"]}')], 'groupMapping.staff'];
        yield 'group の ID が 0' => [['managedGroups' => '0'], 'managedGroups'];
        yield '知らない認証方式' => [['clientAuthMethod' => $wrap('private_key_jwt')], 'clientAuthMethod'];
        yield '知らない制御モード' => [['nativeLoginMode' => $wrap('deny')], 'nativeLoginMode'];
        yield '時刻のずれが範囲外' => [['clockSkewSeconds' => 601], 'clockSkewSeconds'];
        yield '時刻のずれが数値でない' => [['clockSkewSeconds' => 'abc'], 'clockSkewSeconds'];
        yield '割合が範囲外' => [['syncMaxDisableRatio' => 101], 'syncMaxDisableRatio'];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidSettings')]
    public function testRejectsInvalidValuesWithTheSettingName(array $overrides, string $expectedName): void
    {
        $this->expectException(InvalidSettingException::class);
        $this->expectExceptionMessage($expectedName . ':');

        Settings::fromDataObject(self::data($overrides));
    }
}
