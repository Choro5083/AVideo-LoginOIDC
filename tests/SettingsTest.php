<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Tests;

use LoginOIDC\ClientAuthMethod;
use LoginOIDC\InvalidSettingException;
use LoginOIDC\NativeLoginMode;
use LoginOIDC\Settings;
use LoginOIDC\SyncRoleSource;
use LoginOIDC\Tests\Support\SettingsFactory;
use LoginOIDC\UserFlag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private static function data(array $overrides = []): object
    {
        return SettingsFactory::data($overrides);
    }

    public function testReadsDefaults(): void
    {
        $settings = Settings::fromDataObject(self::data());

        self::assertSame('https://idp.example.com/realms/test', $settings->issuer, 'the trailing slash is removed');
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

            self::assertSame('from-file', $settings->resolveClientSecret(), 'surrounding line breaks are removed');
        } finally {
            unlink($file);
        }
        self::assertSame('inline', Settings::fromDataObject(self::data(['clientSecret' => 'inline']))->resolveClientSecret());
    }

    public function testClientSecretMustBeAvailable(): void
    {
        try {
            Settings::fromDataObject(self::data(['clientSecret' => '']))->resolveClientSecret();
            self::fail('passed without a secret');
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

        yield 'unknown flag' => [['managedFlags' => 'isAdmin, isRoot'], 'managedFlags'];
        yield 'broken JSON' => [['flagMapping' => $wrap('{"admin": ')], 'flagMapping'];
        yield 'mapping is a list' => [['flagMapping' => $wrap('["isAdmin"]')], 'flagMapping'];
        yield 'mapped value is not a list' => [['flagMapping' => $wrap('{"admin": "isAdmin"}')], 'flagMapping.admin'];
        yield 'unknown flag in the mapped value' => [['flagMapping' => $wrap('{"admin": ["root"]}')], 'flagMapping.admin'];
        yield 'group ID is not a number' => [['groupMapping' => $wrap('{"staff": ["editors"]}')], 'groupMapping.staff'];
        yield 'group ID is 0' => [['managedGroups' => '0'], 'managedGroups'];
        yield 'unknown authentication method' => [['clientAuthMethod' => $wrap('private_key_jwt')], 'clientAuthMethod'];
        yield 'unknown control mode' => [['nativeLoginMode' => $wrap('deny')], 'nativeLoginMode'];
        yield 'clock skew out of range' => [['clockSkewSeconds' => 601], 'clockSkewSeconds'];
        yield 'clock skew is not a number' => [['clockSkewSeconds' => 'abc'], 'clockSkewSeconds'];
        yield 'ratio out of range' => [['syncMaxDisableRatio' => 101], 'syncMaxDisableRatio'];
        yield 'negative rate limit' => [['loginRateLimit' => '-1'], 'loginRateLimit'];
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
