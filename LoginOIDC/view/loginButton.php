<?php
// SPDX-License-Identifier: MIT

// ログイン画面（view/userLogin.php）から読み込まれる。
global $global;

try {
    // 既定のラベルには訳がある。利用者が書き換えたラベルは、訳が無いのでそのまま出る。
    $loginOidcLabel = LoginOIDC::t(LoginOIDC::settings()->buttonLabel);
} catch (\LoginOIDC\InvalidSettingException $e) {
    _error_log('LoginOIDC: sign-in button hidden because the settings are invalid: ' . $e->getMessage(), AVideoLog::$ERROR);

    return;
}
$loginOidcUrl = $global['webSiteRootURL'] . 'plugin/LoginOIDC/login.php';
// 本体のログイン画面は、ログイン後の戻り先を redirectUri で持っている。本体がすでに
// HTML 用にエスケープしているので、元に戻してから渡す（login.php が安全な URL かを確かめる）。
if (!empty($_GET['redirectUri']) && is_string($_GET['redirectUri'])) {
    $loginOidcUrl .= '?' . http_build_query(['returnTo' => html_entity_decode($_GET['redirectUri'], ENT_QUOTES, 'UTF-8')]);
}
?>
<div class="col-md-12">
    <a id="loginOIDC" class="btn btn-primary btn-block" href="<?php echo htmlspecialchars($loginOidcUrl, ENT_QUOTES); ?>">
        <span class="fas fa-sign-in-alt"></span> <?php echo htmlspecialchars($loginOidcLabel, ENT_QUOTES); ?>
    </a>
</div>
