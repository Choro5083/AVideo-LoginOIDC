<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Admin;

use LoginOIDC\NativeLoginMode;
use LoginOIDC\Native\IpMatcher;
use LoginOIDC\Settings;
use LoginOIDC\Sync\KeycloakDirectory;
use LoginOIDC\SyncRoleSource;

/**
 * Inspects the settings.
 *
 * AVideo has no hook for a plugin to validate its settings when they are saved, so wrong
 * settings can be saved. The problems are found here afterwards and shown on the admin pages.
 *
 * Only what the values alone can tell is checked here. Nothing that needs the provider.
 */
final class SettingsInspector
{
    /**
     * @param  callable(int): bool    $groupExists whether AVideo has a user group with that ID
     * @param  callable(string): bool $userExists  whether AVideo has a user with that username
     * @param  list<string>           $trustedProxies AVideo's $global['trustedProxies']
     * @return list<Finding>
     */
    public static function inspect(Settings $settings, callable $groupExists, callable $userExists, array $trustedProxies = []): array
    {
        return [
            ...self::connection($settings),
            ...self::permissions($settings, $groupExists),
            ...self::nativeLogin($settings, $userExists, $trustedProxies),
            ...self::sync($settings),
        ];
    }

    /** @return list<Finding> */
    private static function connection(Settings $settings): array
    {
        $findings = [];
        if ($settings->issuer === '') {
            $findings[] = Finding::error('issuer', 'Not set. Sign-in through the provider is disabled until it is.');
        } else {
            $parts = parse_url($settings->issuer);
            $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
            if (!is_array($parts) || empty($parts['host']) || !in_array($scheme, ['https', 'http'], true)) {
                $findings[] = Finding::error('issuer', 'Must be an http(s) URL.');
            } elseif (isset($parts['query']) || isset($parts['fragment'])) {
                $findings[] = Finding::error('issuer', 'Must not contain a query or a fragment.');
            } elseif ($scheme === 'http' && !$settings->allowInsecureHttp) {
                $findings[] = Finding::error('issuer', 'Uses http. Use https, or enable allowInsecureHttp for development.');
            }
        }
        if ($settings->clientId === '') {
            $findings[] = Finding::error('clientId', 'Not set.');
        }
        if ($settings->clientSecret === '' && $settings->clientSecretFile === '') {
            $findings[] = Finding::error('clientSecret', 'Neither clientSecret nor clientSecretFile is set.');
        }
        if (!in_array('openid', $settings->scopes, true)) {
            $findings[] = Finding::error('scopes', 'Must include "openid". Without it the provider returns no ID Token.');
        }
        if ($settings->allowInsecureHttp) {
            $findings[] = Finding::warning(
                'allowInsecureHttp',
                'Enabled. Traffic to the provider can be read and altered on the network. Use it for development only.',
            );
        }

        return $findings;
    }

    /**
     * @param  callable(int): bool $groupExists
     * @return list<Finding>
     */
    private static function permissions(Settings $settings, callable $groupExists): array
    {
        $findings = [];

        // --- Flags ---
        $managed = array_map(static fn ($flag): string => $flag->value, $settings->managedFlags);
        if ($managed !== [] && $settings->rolesClaimPath === '') {
            $findings[] = Finding::warning('rolesClaimPath', 'Not set, so managedFlags has no effect.');
        }
        if ($managed === [] && $settings->rolesClaimPath !== '' && $settings->flagMapping !== []) {
            $findings[] = Finding::warning('managedFlags', 'Empty, so flagMapping has no effect. List the flags this plugin should control.');
        }
        if ($managed !== [] && $settings->rolesClaimPath !== '') {
            $mapped = [];
            foreach ($settings->flagMapping as $claimValue => $flags) {
                foreach ($flags as $flag) {
                    $mapped[$flag->value] = true;
                    if (!in_array($flag->value, $managed, true)) {
                        $findings[] = Finding::warning(
                            'flagMapping',
                            '"%s" maps to %s, which is not in managedFlags. It is ignored.',
                            (string) $claimValue,
                            $flag->value,
                        );
                    }
                }
            }
            foreach ($managed as $flag) {
                if (!isset($mapped[$flag])) {
                    // Managed, but nothing grants it: it is removed from everyone. With isAdmin, no admin is left.
                    $findings[] = Finding::warning(
                        'flagMapping',
                        'No value maps to %s. It will be removed from every user who signs in through the provider.',
                        $flag,
                    );
                }
            }
        }

        // --- user group ---
        if ($settings->managedGroups !== [] && $settings->groupsClaimPath === '') {
            $findings[] = Finding::warning('groupsClaimPath', 'Not set, so managedGroups has no effect.');
        }
        foreach ($settings->managedGroups as $groupId) {
            if (!$groupExists($groupId)) {
                $findings[] = Finding::error('managedGroups', 'User group %s does not exist.', $groupId);
            }
        }
        foreach ($settings->groupMapping as $claimValue => $groupIds) {
            foreach ($groupIds as $groupId) {
                if (!in_array($groupId, $settings->managedGroups, true)) {
                    $findings[] = Finding::warning(
                        'groupMapping',
                        '"%s" maps to user group %s, which is not in managedGroups. It is ignored.',
                        (string) $claimValue,
                        $groupId,
                    );
                }
            }
        }

        // --- Requirement to sign in ---
        if (($settings->requiredClaimPath === '') !== ($settings->requiredClaimValues === [])) {
            $findings[] = Finding::warning(
                'requiredClaimPath',
                'requiredClaimPath and requiredClaimValues must both be set. With only one, no requirement is applied.',
            );
        }

        return $findings;
    }

    /**
     * @param  callable(string): bool $userExists
     * @param  list<string>           $trustedProxies
     * @return list<Finding>
     */
    private static function nativeLogin(Settings $settings, callable $userExists, array $trustedProxies): array
    {
        $findings = [];
        if ($settings->nativeLoginMode === NativeLoginMode::Allowlist) {
            if ($settings->nativeLoginAllowlist === []) {
                $findings[] = Finding::warning(
                    'nativeLoginAllowlist',
                    'Empty. Nobody can sign in with a password, so there is no way in when the provider is down.',
                );
            }
            foreach ($settings->nativeLoginAllowlist as $username) {
                if (!$userExists($username)) {
                    $findings[] = Finding::warning('nativeLoginAllowlist', 'User "%s" does not exist.', $username);
                }
            }
        }
        foreach ($settings->encoderAddresses as $address) {
            if (!IpMatcher::isValidRange($address)) {
                $findings[] = Finding::error('encoderAddresses', '"%s" is not an IP address or a CIDR range. It matches nothing.', $address);
            } elseif (IpMatcher::coversEverything($address)) {
                $findings[] = Finding::error('encoderAddresses', '"%s" covers every address, which disables the native sign-in control.', $address);
            }
        }
        // Behind a reverse proxy, requests through the proxy come from the proxy's address. An Encoder
        // address that covers the proxy exempts everyone who comes through it.
        foreach ($trustedProxies as $proxy) {
            if (IpMatcher::matchesAny($proxy, $settings->encoderAddresses)) {
                $findings[] = Finding::error(
                    'encoderAddresses',
                    'Covers the trusted reverse proxy %s. Everyone who comes through the proxy would be exempt. List the Encoder\'s own address.',
                    $proxy,
                );
            }
        }
        if ($settings->nativeLoginMode !== NativeLoginMode::Allow && $settings->encoderAddresses === []) {
            $findings[] = Finding::warning(
                'encoderAddresses',
                'Empty while native sign-in is restricted. Uploads through the Encoder will fail for users linked to the provider.',
            );
        }

        return $findings;
    }

    /** @return list<Finding> */
    private static function sync(Settings $settings): array
    {
        if (!$settings->syncEnabled) {
            return [];
        }
        $findings = [];
        if ($settings->syncClientId === '') {
            $findings[] = Finding::error('syncClientId', 'Not set.');
        }
        if ($settings->syncAdminUrl === '' && KeycloakDirectory::adminUrlFromIssuer($settings->issuer) === '') {
            $findings[] = Finding::error('syncAdminUrl', 'Cannot be derived from the issuer. Set it explicitly.');
        }
        if ($settings->syncRoleSource === SyncRoleSource::ClientRoles) {
            if ($settings->syncRoleClientId === '') {
                $findings[] = Finding::error('syncRoleClientId', 'Required when syncRoleSource is client roles.');
            }
            $findings[] = Finding::warning(
                'syncRoleSource',
                'Reading client roles needs view-clients, which lets the sync account read every client secret of the realm. Groups need only view-users.',
            );
        }
        if ($settings->rolesClaimPath === '' && $settings->groupsClaimPath === '' && $settings->requiredClaimPath === '') {
            $findings[] = Finding::warning('syncEnabled', 'No claim path is set, so the sync only disables and re-enables users.');
        }

        return $findings;
    }
}
