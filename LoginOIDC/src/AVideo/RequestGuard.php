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
use LoginOIDC\Session\SessionMarker;
use LoginOIDC\Session\SessionPolicy;
use LoginOIDC\Settings;

/**
 * 本体のフック（リクエストの開始時、ログインの成立時）から呼ばれる処理。
 *
 * 判定そのものは Session\SessionPolicy と Native\NativeLoginPolicy にある。
 * ここは、本体のセッションやグローバル変数との受け渡しだけを行う。
 */
final class RequestGuard
{
    private const SESSION_KEY = 'loginOIDC';

    /** callback.php が、IdP 経由のログインであることを onUserSignIn に伝えるための印。 */
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

    /** リクエストの開始時に呼ばれる（本体の getStart フック）。 */
    public static function onRequestStart(): void
    {
        if (isCommandLineInterface()) {
            return;
        }
        $marker = self::marker();
        if ($marker === null) {
            return;
        }
        // 印は IdP 経由でログインしたユーザのもの。別のユーザのセッションになっていたら、印の方を捨てる。
        if ((int) ($_SESSION['user']['id'] ?? 0) !== $marker->userId) {
            self::clearMarker();

            return;
        }

        try {
            $settings = \LoginOIDC::settings();
        } catch (InvalidSettingException $e) {
            _error_log('LoginOIDC: invalid settings: ' . $e->getMessage(), \AVideoLog::$ERROR);

            return;
        }

        if (self::isLogoffRequest()) {
            self::logoutAtProvider($settings, $marker);

            return;
        }

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

    /** ログインが成立した直後に呼ばれる（本体の onUserSignIn フック）。 */
    public static function onUserSignIn(int $userId): void
    {
        if (self::$signingInViaProvider) {
            return;
        }

        try {
            $settings = \LoginOIDC::settings();
            $linked = (new SessionRegistry())->isLinked($userId);
        } catch (InvalidSettingException | DbException $e) {
            // 判定できないときにログインを通すと、制御をすり抜けられる。止める側に倒す。
            _error_log('LoginOIDC: cannot evaluate the native sign-in, denying it: ' . $e->getMessage(), \AVideoLog::$ERROR);
            \User::logoff();

            return;
        }

        $username = (string) ($_SESSION['user']['user'] ?? '');
        $reason = NativeLoginPolicy::reasonToDeny($settings, new NativeLoginAttempt(
            username: $username,
            linked: $linked,
            viaCookie: !empty($GLOBALS['justTryToRecreateLoginFromCookie']),
            // 転送ヘッダ（X-Forwarded-For）は見ない。送る側が自由に書けるため、Encoder の例外に使えない。
            remoteAddress: (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ));
        if ($reason === null) {
            return;
        }

        _error_log("LoginOIDC: native sign-in of user {$username} (id {$userId}) denied: {$reason}", \AVideoLog::$SECURITY);
        // 本体にはログインの前に割り込むフックが無い。成立した直後に取り消す。
        \User::logoff();
    }

    private static function isLogoffRequest(): bool
    {
        return str_ends_with((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/objects/logoff.php');
    }

    /**
     * AVideo からログアウトし、IdP のログアウトへリダイレクトする（RP-Initiated Logout）。
     *
     * IdP へ飛ばせないとき（設定で無効、IdP が対応していない、Discovery が失敗）は何もせずに戻り、
     * 本体のログアウトをそのまま続けさせる。
     */
    private static function logoutAtProvider(Settings $settings, SessionMarker $marker): void
    {
        global $global;

        if (!$settings->rpInitiatedLogout) {
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
