<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Admin;

use LoginOIDC\AVideo\Db;
use LoginOIDC\AVideo\DbException;
use LoginOIDC\Factory;
use LoginOIDC\InvalidSettingException;
use LoginOIDC\Oidc\Cache;
use LoginOIDC\Oidc\Discovery;
use LoginOIDC\Oidc\OidcException;
use LoginOIDC\Settings;

/** 管理画面に出す、設定の検査結果を集める。 */
final class SettingsReport
{
    /**
     * 設定の値だけで分かる問題。通信はしないので、プラグインの一覧を出すたびに呼んでよい。
     *
     * @param  callable(): Settings $loadSettings 設定を読む。読めなければ InvalidSettingException
     * @return list<Finding>
     */
    public static function quick(callable $loadSettings): array
    {
        try {
            $settings = $loadSettings();
        } catch (InvalidSettingException $e) {
            // 項目名は例外のメッセージの先頭に入っている（「項目名: 説明」）。
            [$setting, $message] = array_pad(explode(': ', $e->getMessage(), 2), 2, '');

            return [Finding::error($message === '' ? 'settings' : $setting, '%s', $message === '' ? $setting : $message)];
        }

        try {
            return SettingsInspector::inspect($settings, self::groupExists(...), self::userExists(...));
        } catch (DbException $e) {
            return [Finding::error('settings', 'Cannot check against the database: %s', $e->getMessage())];
        }
    }

    /**
     * IdP と通信して分かる問題。管理者が検査のページを開いたときにだけ呼ぶ。
     *
     * @return list<Finding>
     */
    public static function provider(Settings $settings, Cache $cache): array
    {
        if (!$settings->isConfigured()) {
            return [];
        }
        $findings = [];
        try {
            $settings->resolveClientSecret();
        } catch (InvalidSettingException $e) {
            [$setting, $message] = array_pad(explode(': ', $e->getMessage(), 2), 2, '');
            $findings[] = Finding::error($setting, '%s', $message);
        }

        try {
            $metadata = (new Discovery(Factory::httpClient($settings), $cache, $settings->allowInsecureHttp))
                ->fetch($settings->issuer);
        } catch (OidcException $e) {
            return [...$findings, Finding::error('issuer', 'The provider cannot be reached or is misconfigured: %s', $e->getMessage())];
        }

        if (!in_array($settings->clientAuthMethod->value, $metadata->tokenEndpointAuthMethods, true)) {
            $findings[] = Finding::error(
                'clientAuthMethod',
                'The provider does not support %s. Supported: %s',
                $settings->clientAuthMethod->value,
                implode(', ', $metadata->tokenEndpointAuthMethods),
            );
        }
        if ($settings->useUserInfo && $metadata->userinfoEndpoint === '') {
            $findings[] = Finding::error('useUserInfo', 'Enabled, but the provider has no userinfo_endpoint.');
        }
        if ($settings->rpInitiatedLogout && $metadata->endSessionEndpoint === '') {
            $findings[] = Finding::warning(
                'rpInitiatedLogout',
                'Enabled, but the provider has no end_session_endpoint. Signing out of AVideo will not sign out of the provider.',
            );
        }

        return $findings;
    }

    private static function groupExists(int $groupId): bool
    {
        return Db::select('SELECT 1 FROM users_groups WHERE id = ? LIMIT 1', 'i', [$groupId]) !== [];
    }

    private static function userExists(string $username): bool
    {
        return Db::select('SELECT 1 FROM users WHERE user = ? LIMIT 1', 's', [$username]) !== [];
    }
}
