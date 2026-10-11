<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\AVideo;

use LoginOIDC\Factory;
use LoginOIDC\InvalidSettingException;
use LoginOIDC\Native\NativeLoginAttempt;
use LoginOIDC\Native\NativeLoginPolicy;
use LoginOIDC\Oidc\Discovery;
use LoginOIDC\Oidc\FileCache;
use LoginOIDC\Oidc\OidcException;
use LoginOIDC\Session\AutoRedirectPolicy;
use LoginOIDC\Session\PageRequest;
use LoginOIDC\Session\SessionMarker;
use LoginOIDC\Session\SessionPolicy;
use LoginOIDC\Settings;

/**
 * Called from AVideo's hooks (start of a request, successful sign-in).
 *
 * The decisions are in Session\SessionPolicy and Native\NativeLoginPolicy.
 * This class only moves data between them and AVideo's session and globals.
 */
final class RequestGuard
{
    private const SESSION_KEY = 'loginOIDC';

    /** Set by callback.php to tell onUserSignIn that the sign-in came through the provider. */
    public static bool $signingInViaProvider = false;

    public static function cache(): FileCache
    {
        return new FileCache(rtrim(getCacheDir(), '/') . '/LoginOIDC');
    }

    public static function marker(): ?SessionMarker
    {
        return SessionMarker::fromArray($_SESSION[self::SESSION_KEY]['marker'] ?? null);
    }

    public static function storeMarker(SessionMarker $marker): void
    {
        _session_start();
        $_SESSION[self::SESSION_KEY]['marker'] = $marker->toArray();
        _session_write_close();
    }

    private static function clearMarker(): void
    {
        _session_start();
        unset($_SESSION[self::SESSION_KEY]['marker']);
        _session_write_close();
    }

    /** Called at the start of a request (AVideo's getStart hook). */
    public static function onRequestStart(): void
    {
        if (isCommandLineInterface()) {
            return;
        }
        try {
            $settings = \LoginOIDC::settings();
        } catch (InvalidSettingException $e) {
            _error_log('LoginOIDC: invalid settings: ' . $e->getMessage(), \AVideoLog::$ERROR);

            return;
        }

        $marker = self::marker();
        // The marker belongs to the user who signed in through the provider. If the session is someone else's now, drop the marker.
        if ($marker !== null && (int) ($_SESSION['user']['id'] ?? 0) !== $marker->userId) {
            self::clearMarker();
            $marker = null;
        }

        if ($marker !== null) {
            if (self::isLogoffRequest()) {
                self::logoutAtProvider($settings, $marker);

                return;
            }
            self::endSessionIfRevoked($marker);
        }

        self::redirectToProviderIfAnonymous($settings);
    }

    private static function endSessionIfRevoked(SessionMarker $marker): void
    {
        try {
            $reason = SessionPolicy::reasonToEnd($marker, (new SessionRegistry())->state($marker));
        } catch (DbException $e) {
            _error_log('LoginOIDC: cannot check the session state: ' . $e->getMessage(), \AVideoLog::$ERROR);

            return;
        }
        if ($reason !== null) {
            _error_log("LoginOIDC: ending the session of user id {$marker->userId}: {$reason}", \AVideoLog::$SECURITY);
            \User::logoff();
            self::clearMarker();
        }
    }

    /** Sends an anonymous page view to the provider's sign-in, when configured. */
    private static function redirectToProviderIfAnonymous(Settings $settings): void
    {
        global $global;

        // Do the cheap checks first. User::isLogged() tries to restore a sign-in from the cookie,
        // so it is not called for requests that are out of scope anyway (APIs, video files).
        $request = new PageRequest(
            method: (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            scriptName: (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
            accept: (string) ($_SERVER['HTTP_ACCEPT'] ?? ''),
            ajax: strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest',
            remoteAddress: (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        );
        if (!AutoRedirectPolicy::shouldRedirect($settings, $request) || \User::isLogged()) {
            return;
        }

        $login = $global['webSiteRootURL'] . 'plugin/LoginOIDC/login.php?'
            . http_build_query(['returnTo' => (string) ($_SERVER['REQUEST_URI'] ?? '/')]);
        header('Location: ' . $login, true, 302);
        exit;
    }

    /** Called right after a sign-in succeeded (AVideo's onUserSignIn hook). */
    public static function onUserSignIn(int $userId): void
    {
        if (self::$signingInViaProvider) {
            return;
        }

        try {
            $settings = \LoginOIDC::settings();
            $linked = (new SessionRegistry())->isLinked($userId);
        } catch (InvalidSettingException | DbException $e) {
            // Letting the sign-in through when it cannot be evaluated would be a way around the control. Deny.
            _error_log('LoginOIDC: cannot evaluate the native sign-in, denying it: ' . $e->getMessage(), \AVideoLog::$ERROR);
            \User::logoff();

            return;
        }

        $username = (string) ($_SESSION['user']['user'] ?? '');
        $reason = NativeLoginPolicy::reasonToDeny($settings, new NativeLoginAttempt(
            username: $username,
            linked: $linked,
            viaCookie: self::isRestoringFromCookie(),
            // Forwarding headers (X-Forwarded-For) are ignored. The sender controls them, so they cannot grant the Encoder exception.
            remoteAddress: (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ));
        if ($reason === null) {
            if ($linked) {
                // Sessions of linked users get a marker even when they start with a native sign-in.
                // Without it they would escape revocation and logout. AVideo is in the middle of its
                // sign-in and has the session open, so write to it without closing it.
                $_SESSION[self::SESSION_KEY]['marker'] = (new SessionMarker($userId, '', time(), ''))->toArray();
            }

            return;
        }

        _error_log("LoginOIDC: native sign-in of user {$username} (id {$userId}) denied: {$reason}", \AVideoLog::$SECURITY);
        // AVideo has no hook before the sign-in. Cancel it right after it succeeded.
        \User::logoff();
    }

    /**
     * Whether the current sign-in is a restoration from the cookie.
     *
     * AVideo restores sign-ins in User::recreateLoginFromCookie(). Its own flag
     * ($justTryToRecreateLoginFromCookie) only says that a restoration was attempted and is
     * set even without a cookie. So the call stack is checked to see whether the sign-in
     * was started by the restoration.
     */
    private static function isRestoringFromCookie(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (($frame['function'] ?? '') === 'recreateLoginFromCookie') {
                return true;
            }
        }

        return false;
    }

    private static function isLogoffRequest(): bool
    {
        return str_ends_with((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/objects/logoff.php');
    }

    /**
     * Signs out of AVideo and redirects to the provider's logout (RP-Initiated Logout).
     *
     * When the provider cannot be used (disabled in the settings, no logout endpoint, discovery
     * failed), it returns without doing anything and AVideo's own logout carries on.
     */
    private static function logoutAtProvider(Settings $settings, SessionMarker $marker): void
    {
        global $global;

        // A marker without an ID Token belongs to a session that started with a native sign-in. It is not
        // tied to a provider session, so the provider is not involved.
        if (!$settings->rpInitiatedLogout || $marker->idToken === '') {
            self::clearMarker();

            return;
        }
        try {
            $endpoint = (new Discovery(Factory::httpClient($settings), self::cache(), $settings->allowInsecureHttp))
                ->fetch($settings->issuer)
                ->endSessionEndpoint;
        } catch (OidcException $e) {
            _error_log('LoginOIDC: cannot log out at the provider: ' . $e->getMessage(), \AVideoLog::$ERROR);
            $endpoint = '';
        }
        if ($endpoint === '') {
            self::clearMarker();

            return;
        }

        \User::logoff();
        self::clearMarker();

        $query = http_build_query([
            'id_token_hint' => $marker->idToken,
            'client_id' => $settings->clientId,
            'post_logout_redirect_uri' => $global['webSiteRootURL'],
        ], '', '&', PHP_QUERY_RFC3986);
        header('Location: ' . $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . $query, true, 302);
        exit;
    }
}
