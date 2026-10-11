<?php
// SPDX-License-Identifier: MIT

// ログインの入口。認可リクエストを組み立てて IdP へリダイレクトする。

declare(strict_types=1);

use LoginOIDC\InvalidSettingException;
use LoginOIDC\Oidc\OidcException;

require_once __DIR__ . '/../../videos/configuration.php';
require_once __DIR__ . '/bootstrap.php';

loginOidcRequireEnabled();
enforceRateLimit('loginoidc_login', 30, 300);

// 戻り先は returnTo で受ける。本体は redirectUri という名前の引数を、絶対 URL でなければ捨て、
// HTML 用にエスケープして書き換える（objects/security.php）ので、その名前は使わない。
$returnTo = $_GET['returnTo'] ?? '';
if (!is_string($returnTo) || !isSafeRedirectURL($returnTo)) {
    // 外部の URL へ戻すと、ログイン直後の利用者を別のサイトへ誘導できてしまう。
    $returnTo = $global['webSiteRootURL'];
}

try {
    [$url, $pending] = loginOidcFlow()->start($returnTo);
} catch (OidcException | InvalidSettingException $e) {
    loginOidcFail('cannot start sign-in', $e);
}

_session_start();
$_SESSION['loginOIDC']['pending'] = $pending->toArray();
_session_write_close();

header('Location: ' . $url, true, 302);
exit;
