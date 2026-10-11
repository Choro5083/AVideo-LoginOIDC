<?php
// SPDX-License-Identifier: MIT

// IdP からのコールバック。認証の結果を検証する。

declare(strict_types=1);

use LoginOIDC\InvalidSettingException;
use LoginOIDC\Oidc\OidcException;
use LoginOIDC\Oidc\PendingLogin;

require_once __DIR__ . '/../../videos/configuration.php';
require_once __DIR__ . '/bootstrap.php';

loginOidcRequireEnabled();
enforceRateLimit('loginoidc_callback', 30, 300);

// 保存しておいた値は1度しか使わない。読んだらすぐ消し、同じコールバックの再送を無効にする。
_session_start();
$pending = PendingLogin::fromArray($_SESSION['loginOIDC']['pending'] ?? null);
unset($_SESSION['loginOIDC']['pending']);
_session_write_close();

try {
    $login = loginOidcFlow()->finish($pending, $_GET);
} catch (OidcException | InvalidSettingException $e) {
    loginOidcFail('sign-in failed', $e);
}

// TODO: ここから先（AVideo のユーザとの突き合わせとログイン）は次の工程で実装する。
_error_log("LoginOIDC: verified sign-in for sub {$login->subject}, but signing in to AVideo is not implemented yet");
loginOidcShowError('LoginOIDC: the sign-in was verified, but signing in to AVideo is not implemented yet.');
