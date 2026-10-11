<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Native;

use LoginOIDC\NativeLoginMode;
use LoginOIDC\Settings;

final class NativeLoginPolicy
{
    /**
     * Returns why the sign-in is denied, or null when it is allowed.
     */
    public static function reasonToDeny(Settings $settings, NativeLoginAttempt $attempt): ?string
    {
        // The Encoder signs in to AVideo as the uploader, with their username and a password token.
        // Blocking that breaks uploads, so sign-ins from the configured addresses are not controlled.
        if (IpMatcher::matchesAny($attempt->remoteAddress, $settings->encoderAddresses)) {
            return null;
        }

        // Users linked to the provider are never restored from the cookie. Otherwise a device holding
        // the cookie would stay signed in after a logout or a deactivation at the provider.
        if ($attempt->linked && $attempt->viaCookie) {
            return 'restoring a sign-in from the cookie is not allowed for users linked to the provider';
        }

        return match ($settings->nativeLoginMode) {
            NativeLoginMode::Allow => null,
            NativeLoginMode::DenyLinked => $attempt->linked
                ? 'native sign-in is not allowed for users linked to the provider'
                : null,
            NativeLoginMode::Allowlist => in_array($attempt->username, $settings->nativeLoginAllowlist, true)
                ? null
                : 'the user is not in the native sign-in allowlist',
        };
    }
}
