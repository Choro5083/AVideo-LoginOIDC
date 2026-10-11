<?php
// SPDX-License-Identifier: MIT

// Included by the sign-in page (view/userLogin.php).
global $global;

try {
    // The default label has translations. A label the admin changed has none and is shown as it is.
    $loginOidcLabel = LoginOIDC::t(LoginOIDC::settings()->buttonLabel);
} catch (\LoginOIDC\InvalidSettingException $e) {
    _error_log('LoginOIDC: sign-in button hidden because the settings are invalid: ' . $e->getMessage(), AVideoLog::$ERROR);

    return;
}
$loginOidcUrl = $global['webSiteRootURL'] . 'plugin/LoginOIDC/login.php';
// AVideo's sign-in page keeps the address to return to in redirectUri, already escaped for HTML.
// Undo the escaping before passing it on (login.php checks that it is a safe URL).
if (!empty($_GET['redirectUri']) && is_string($_GET['redirectUri'])) {
    $loginOidcUrl .= '?' . http_build_query(['returnTo' => html_entity_decode($_GET['redirectUri'], ENT_QUOTES, 'UTF-8')]);
}
?>
<div class="col-md-12">
    <a id="loginOIDC" class="btn btn-primary btn-block" href="<?php echo htmlspecialchars($loginOidcUrl, ENT_QUOTES); ?>">
        <span class="fas fa-sign-in-alt"></span> <?php echo htmlspecialchars($loginOidcLabel, ENT_QUOTES); ?>
    </a>
</div>
