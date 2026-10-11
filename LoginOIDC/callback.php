<?php
// SPDX-License-Identifier: MIT

// IdP からのコールバック。認証の結果を検証し、AVideo にログインさせる。

declare(strict_types=1);

use LoginOIDC\AVideo\DbException;
use LoginOIDC\AVideo\RequestGuard;
use LoginOIDC\AVideo\SessionRegistry;
use LoginOIDC\Factory;
use LoginOIDC\InvalidSettingException;
use LoginOIDC\Oidc\OidcException;
use LoginOIDC\Oidc\PendingLogin;
use LoginOIDC\Session\SessionMarker;
use LoginOIDC\User\LoginDeniedException;

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

try {
    $account = Factory::provisioner(LoginOIDC::settings())->provision($login);
} catch (LoginDeniedException $e) {
    loginOidcFail('sign-in denied', $e, 'Your account is not allowed to sign in here.');
} catch (InvalidSettingException | DbException | \RuntimeException $e) {
    loginOidcFail('cannot prepare the user', $e);
}

// 本体のログイン処理に任せる（セッション、Cookie、セッション ID の付け替え、他のプラグインへの通知）。
// パスワードの照合は行わない。本人であることは、IdP の認証を検証して確かめてある。
RequestGuard::$signingInViaProvider = true;
$status = (new User($account->userId))->login(true);
RequestGuard::$signingInViaProvider = false;
if ($status !== User::USER_LOGGED) {
    loginOidcFail('sign-in rejected by AVideo', new \RuntimeException("User::login returned {$status} for user {$account->username}"));
}

// IdP 経由のログインであることをセッションに記す。ログアウトの連動と、無効化の確認に使う。
$marker = new SessionMarker($account->userId, $login->sessionId, time(), $login->idToken);
RequestGuard::storeMarker($marker);
try {
    (new SessionRegistry())->record($marker);
} catch (DbException $e) {
    // ログイン自体は成立している。記録できないと sid を指定した Back-Channel Logout が効かなくなるので、ログに残す。
    _error_log("LoginOIDC: cannot record the session of user {$account->username}: {$e->getMessage()}", AVideoLog::$ERROR);
}

_error_log(
    "LoginOIDC: user {$account->username} (id {$account->userId}) signed in" . ($account->created ? ' (created)' : ''),
);

header('Location: ' . (isSafeRedirectURL($login->returnTo) ? $login->returnTo : $global['webSiteRootURL']), true, 302);
exit;
