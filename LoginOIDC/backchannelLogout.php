<?php
// SPDX-License-Identifier: MIT

// Logout notice from the provider (OpenID Connect Back-Channel Logout 1.0).
// It is called by the provider's server, not by the user's browser.

declare(strict_types=1);

use LoginOIDC\AVideo\DbException;
use LoginOIDC\AVideo\RequestGuard;
use LoginOIDC\AVideo\SessionRegistry;
use LoginOIDC\Factory;
use LoginOIDC\InvalidSettingException;
use LoginOIDC\Oidc\OidcException;

require_once __DIR__ . '/../../videos/configuration.php';

/** @param array<string, string> $body */
$respond = static function (int $status, array $body = []): never {
    http_response_code($status);
    header('Cache-Control: no-store');
    header('Content-Type: application/json');
    echo $body === [] ? '' : json_encode($body);
    exit;
};

if (empty(AVideoPlugin::loadPluginIfEnabled('LoginOIDC'))) {
    $respond(404);
}
try {
    $settings = LoginOIDC::settings();
} catch (InvalidSettingException $e) {
    _error_log('LoginOIDC: back-channel logout rejected, invalid settings: ' . $e->getMessage(), AVideoLog::$ERROR);
    $respond(500);
}
if (!$settings->backchannelLogout || !$settings->isConfigured()) {
    $respond(404);
}

$logoutToken = $_POST['logout_token'] ?? null;
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_string($logoutToken) || $logoutToken === '') {
    $respond(400, ['error' => 'invalid_request']);
}

try {
    $notice = Factory::logoutTokenVerifier($settings, RequestGuard::cache())->verify($logoutToken);
} catch (OidcException $e) {
    _error_log('LoginOIDC: back-channel logout rejected: ' . $e->getMessage(), AVideoLog::$SECURITY);
    $respond(400, ['error' => 'invalid_request']);
}

try {
    $registry = new SessionRegistry();
    $now = time();
    $ended = $notice->sessionId === '' ? 0 : $registry->revokeSession($notice->sessionId, $now);
    // Without a sid, or when no session is recorded for the sid, end every session of that person.
    // Otherwise a logout would silently do nothing once the record has been cleaned up.
    if ($ended === 0 && $notice->subject !== '') {
        $registry->revokeAllOfSubject($notice->issuer, $notice->subject, $now);
        $scope = 'all sessions of sub ' . $notice->subject;
    } else {
        $scope = "session {$notice->sessionId}";
    }
} catch (DbException $e) {
    _error_log('LoginOIDC: back-channel logout failed: ' . $e->getMessage(), AVideoLog::$ERROR);
    $respond(500);
}

_error_log("LoginOIDC: back-channel logout accepted for {$scope}");
$respond(200);
