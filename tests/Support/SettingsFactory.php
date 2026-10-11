<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Tests\Support;

use LoginOIDC\Settings;

final class SettingsFactory
{
    /**
     * 既定値に相当するデータオブジェクト。選択肢と複数行テキストは、
     * AVideo が保存する形（{type, value}）にしてある。
     *
     * @param array<string, mixed> $overrides
     */
    public static function data(array $overrides = []): object
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
            'loginRateLimit' => 60,
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

    /**
     * 複数行テキストと選択肢の項目は、値だけを渡せば AVideo が保存する形に包む。
     *
     * @param array<string, mixed> $overrides
     */
    public static function make(array $overrides = []): Settings
    {
        $wrapped = ['clientAuthMethod', 'flagMapping', 'groupMapping', 'nativeLoginMode',
                    'nativeLoginAllowlist', 'encoderAddresses', 'syncRoleSource'];
        foreach ($overrides as $name => $value) {
            if (in_array($name, $wrapped, true) && !is_object($value)) {
                $overrides[$name] = (object) ['type' => 'textarea', 'value' => $value];
            }
        }

        return Settings::fromDataObject(self::data($overrides));
    }
}
