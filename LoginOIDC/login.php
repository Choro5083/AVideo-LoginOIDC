<?php
// SPDX-License-Identifier: MIT

// Entry point of the sign-in. Builds the authorization request and redirects to the provider.

declare(strict_types=1);

use LoginOIDC\InvalidSettingException;
use LoginOIDC\Oidc\OidcException;

require_once __DIR__ . '/../../videos/configuration.php';
require_once __DIR__ . '/bootstrap.php';

loginOidcRequireEnabled();
loginOidcRateLimit('loginoidc_login');

// The return address comes in "returnTo". AVideo drops a parameter named "redirectUri" unless it is
// an absolute URL, and rewrites it escaped for HTML (objects/security.php), so that name is avoided.
$returnTo = $_GET['returnTo'] ?? '';
if (!is_string($returnTo) || !isSafeRedirectURL($returnTo)) {
    // Returning to an external URL would let someone send a freshly signed-in user to another site.
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
