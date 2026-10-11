<?php
// SPDX-License-Identifier: MIT

// Callback from the provider. Verifies the result and signs the user in to AVideo.

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
loginOidcRateLimit('loginoidc_callback');

// The stored values are used once. Remove them as soon as they are read, so a replayed callback fails.
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

// Leave the sign-in to AVideo (session, cookies, new session ID, notifying other plugins).
// No password is checked. The identity was established by verifying the provider's authentication.
RequestGuard::$signingInViaProvider = true;
$status = (new User($account->userId))->login(true);
RequestGuard::$signingInViaProvider = false;
if ($status !== User::USER_LOGGED) {
    loginOidcFail('sign-in rejected by AVideo', new \RuntimeException("User::login returned {$status} for user {$account->username}"));
}

// Mark the session as signed in through the provider. Logout linking and the revocation check use it.
$marker = new SessionMarker($account->userId, $login->sessionId, time(), $login->idToken);
RequestGuard::storeMarker($marker);
try {
    (new SessionRegistry())->record($marker);
} catch (DbException $e) {
    // The sign-in itself succeeded. Without the record, a back-channel logout that names a sid has no effect, so log it.
    _error_log("LoginOIDC: cannot record the session of user {$account->username}: {$e->getMessage()}", AVideoLog::$ERROR);
}

_error_log(
    "LoginOIDC: user {$account->username} (id {$account->userId}) signed in" . ($account->created ? ' (created)' : ''),
);

header('Location: ' . (isSafeRedirectURL($login->returnTo) ? $login->returnTo : $global['webSiteRootURL']), true, 302);
exit;
