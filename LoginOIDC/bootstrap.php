<?php
// SPDX-License-Identifier: MIT

// Shared by the plugin's endpoints (login.php, callback.php and so on).

declare(strict_types=1);

use LoginOIDC\AVideo\RequestGuard;
use LoginOIDC\Factory;
use LoginOIDC\InvalidSettingException;
use LoginOIDC\Oidc\LoginFlow;

/** Stops unless the plugin is enabled and configured. */
function loginOidcRequireEnabled(): void
{
    if (empty(AVideoPlugin::loadPluginIfEnabled('LoginOIDC'))) {
        loginOidcShowError(__('LoginOIDC is disabled.', true));
    }
    try {
        $configured = LoginOIDC::settings()->isConfigured();
    } catch (InvalidSettingException $e) {
        loginOidcFail('invalid settings', $e);
    }
    if (!$configured) {
        loginOidcShowError(LoginOIDC::t('LoginOIDC is not configured.'));
    }
}

/** Limits requests from one address to the configured number. 0 means no limit. */
function loginOidcRateLimit(string $operation): void
{
    try {
        $limit = LoginOIDC::settings()->loginRateLimit;
    } catch (InvalidSettingException $e) {
        loginOidcFail('invalid settings', $e);
    }
    if ($limit > 0) {
        enforceRateLimit($operation, $limit, 300);
    }
}

/** @throws InvalidSettingException */
function loginOidcFlow(): LoginFlow
{
    return Factory::loginFlow(
        LoginOIDC::settings(),
        LoginOIDC::callbackUrl(),
        RequestGuard::cache(),
    );
}

/**
 * Shows an error page and stops.
 *
 * AVideo's own forbiddenPage() is not used. It sends an anonymous visitor to the sign-in
 * page and back to the original URL afterwards, so a failed sign-in would come back
 * to the failed callback.
 */
function loginOidcShowError(string $message): never
{
    global $global;

    $_REQUEST['403ErrorMsg'] = $message;
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    include $global['systemRootPath'] . 'view/forbiddenPage.php';
    exit;
}

/**
 * Logs the failure and stops.
 *
 * The details go to the log only. They can contain the provider's response and what
 * exactly failed to verify, which would help an attacker. The page shows only a
 * reference to find the log line.
 */
function loginOidcFail(string $summary, \Throwable $e, string $userMessage = 'Sign-in failed. Please try again.'): never
{
    $reference = bin2hex(random_bytes(4));
    _error_log("LoginOIDC [{$reference}]: {$summary}: {$e->getMessage()}", AVideoLog::$ERROR);
    // The page is translated. The log stays in English so it reads the same whatever the visitor's language.
    loginOidcShowError(
        LoginOIDC::t($userMessage) . ' '
        . sprintf(LoginOIDC::t('If the problem persists, contact the administrator with this reference: %s'), $reference),
    );
}
