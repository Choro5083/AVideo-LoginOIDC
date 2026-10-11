<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Session;

use LoginOIDC\Native\IpMatcher;
use LoginOIDC\Settings;

/** 未ログインの利用者を、IdP のログインへ自動で送るかどうか。 */
final class AutoRedirectPolicy
{
    /**
     * 送ってはいけない場所。
     *
     * - このプラグイン自身のエンドポイント（送ると、ログインの途中やエラー画面で回り続ける）
     * - 本体のログイン画面とログイン・ログアウトの処理（ネイティブログインに使う。
     *   IdP が止まったときの入口でもある）
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
        // 対象は、ブラウザが画面として開くリクエストだけ。API、JSON、動画ファイル、
        // JavaScript からの通信をリダイレクトすると、呼び出し側が壊れる。
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
