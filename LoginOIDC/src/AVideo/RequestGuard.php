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
        try {
            $settings = \LoginOIDC::settings();
        } catch (InvalidSettingException $e) {
            _error_log('LoginOIDC: invalid settings: ' . $e->getMessage(), \AVideoLog::$ERROR);

            return;
        }

        $marker = self::marker();
        // 印は IdP 経由でログインしたユーザのもの。別のユーザのセッションになっていたら、印の方を捨てる。
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

    /** 未ログインの画面表示を、設定に従って IdP のログインへ送る。 */
    private static function redirectToProviderIfAnonymous(Settings $settings): void
    {
        global $global;

        // 先に安い判定を済ませる。User::isLogged() は Cookie からの復元を試みるため、
        // 対象外のリクエスト（API や動画）では呼ばない。
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
            viaCookie: self::isRestoringFromCookie(),
            // 転送ヘッダ（X-Forwarded-For）は見ない。送る側が自由に書けるため、Encoder の例外に使えない。
            remoteAddress: (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ));
        if ($reason === null) {
            if ($linked) {
                // IdP に紐付いたユーザのセッションには、ネイティブログインで始まったものにも印を付ける。
                // 付けないと、無効化やログアウトの対象から漏れる。本体のログイン処理の途中で、
                // セッションは開いているので、閉じずに書くだけにする。
                $_SESSION[self::SESSION_KEY]['marker'] = (new SessionMarker($userId, '', time(), ''))->toArray();
            }

            return;
        }

        _error_log("LoginOIDC: native sign-in of user {$username} (id {$userId}) denied: {$reason}", \AVideoLog::$SECURITY);
        // 本体にはログインの前に割り込むフックが無い。成立した直後に取り消す。
        \User::logoff();
    }

    /**
     * いまのログインが、Cookie からの復元によるものか。
     *
     * 本体は復元を User::recreateLoginFromCookie() で行う。本体が持つ印
     * （$justTryToRecreateLoginFromCookie）は「復元を試みた」ことを示すだけで、
     * Cookie が無くても立つ。そのため、ログイン処理を呼んだのが復元の処理かどうかを
     * 呼び出し元から確かめる。
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
     * AVideo からログアウトし、IdP のログアウトへリダイレクトする（RP-Initiated Logout）。
     *
     * IdP へ飛ばせないとき（設定で無効、IdP が対応していない、Discovery が失敗）は何もせずに戻り、
     * 本体のログアウトをそのまま続けさせる。
     */
    private static function logoutAtProvider(Settings $settings, SessionMarker $marker): void
    {
        global $global;

        // ID Token を持たない印は、ネイティブログインで始まったセッションのもの。IdP のセッションとは
        // 結び付いていないので、IdP へは送らない。
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
