<?php
// SPDX-License-Identifier: MIT

// ログイン画面（view/userLogin.php）から読み込まれる。
global $global;

try {
    $loginOidcLabel = LoginOIDC::settings()->buttonLabel;
} catch (\LoginOIDC\InvalidSettingException $e) {
    _error_log('LoginOIDC: sign-in button hidden because the settings are invalid: ' . $e->getMessage(), AVideoLog::$ERROR);

    return;
}
$loginOidcUrl = $global['webSiteRootURL'] . 'plugin/LoginOIDC/login.php';
?>
<div class="col-md-12">
    <a id="loginOIDC" class="btn btn-primary btn-block" href="<?php echo htmlspecialchars($loginOidcUrl, ENT_QUOTES); ?>">
        <span class="fas fa-sign-in-alt"></span> <?php echo htmlspecialchars($loginOidcLabel, ENT_QUOTES); ?>
    </a>
</div>
