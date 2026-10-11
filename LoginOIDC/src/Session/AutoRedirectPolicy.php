<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Session;

use LoginOIDC\Native\IpMatcher;
use LoginOIDC\Settings;

/** Whether to send an anonymous visitor to the provider's sign-in automatically. */
final class AutoRedirectPolicy
{
    /**
     * Places a visitor must never be redirected from.
     *
     * - This plugin's own endpoints (redirecting there loops during a sign-in or on an error page)
     * - AVideo's sign-in page and its sign-in and logout handlers (used for native sign-in,
     *   which is also the way in when the provider is down)
     */
    private const EXCLUDED_SCRIPTS = [
        '/view/user.php',
        '/objects/login.json.php',
        '/objects/logoff.php',
    ];

    private const EXCLUDED_PREFIXES = [
        '/plugin/LoginOIDC/',
    ];

    public static function shouldRedirect(Settings $settings, PageRequest $request): bool
    {
        if (!$settings->autoRedirect || !$settings->isConfigured()) {
            return false;
        }
        // Only requests a browser makes to show a page. Redirecting APIs, JSON, video files
        // or requests from JavaScript breaks the caller.
        if (strtoupper($request->method) !== 'GET' || $request->ajax) {
            return false;
        }
        if (!str_contains(strtolower($request->accept), 'text/html')) {
            return false;
        }
        if (str_ends_with($request->scriptName, '.json.php')) {
            return false;
        }
        foreach (self::EXCLUDED_SCRIPTS as $script) {
            if (str_ends_with($request->scriptName, $script)) {
                return false;
            }
        }
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_contains($request->scriptName, $prefix)) {
                return false;
            }
        }

        return !IpMatcher::matchesAny($request->remoteAddress, $settings->encoderAddresses);
    }
}
