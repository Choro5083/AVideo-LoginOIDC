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

/** Collects the findings shown on the admin pages. */
final class SettingsReport
{
    /**
     * Problems visible from the values alone. No network access, so it is cheap enough for every plugin list.
     *
     * @param  callable(): Settings $loadSettings reads the settings, or throws InvalidSettingException
     * @return list<Finding>
     */
    public static function quick(callable $loadSettings): array
    {
        try {
            $settings = $loadSettings();
        } catch (InvalidSettingException $e) {
            // The exception message starts with the setting name ("name: explanation").
            [$setting, $message] = array_pad(explode(': ', $e->getMessage(), 2), 2, '');

            return [Finding::error($message === '' ? 'settings' : $setting, '%s', $message === '' ? $setting : $message)];
        }

        try {
            return SettingsInspector::inspect($settings, self::groupExists(...), self::userExists(...), self::trustedProxies());
        } catch (DbException $e) {
            return [Finding::error('settings', 'Cannot check against the database: %s', $e->getMessage())];
        }
    }

    /**
     * AVideo's $global['trustedProxies'] (configuration.php). Only the strings, in case it holds something else.
     *
     * @return list<string>
     */
    private static function trustedProxies(): array
    {
        global $global;
        $proxies = $global['trustedProxies'] ?? [];

        return is_array($proxies) ? array_values(array_filter($proxies, 'is_string')) : [];
    }

    /**
     * Problems that need the provider. Called only when an admin opens the check page.
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
