<?php
// SPDX-License-Identifier: MIT

// プラグインの各エンドポイント（login.php、callback.php など）が共通で使う処理。

declare(strict_types=1);

use LoginOIDC\AVideo\RequestGuard;
use LoginOIDC\Factory;
use LoginOIDC\InvalidSettingException;
use LoginOIDC\Oidc\LoginFlow;

/** プラグインが無効、または設定が済んでいなければ止める。 */
function loginOidcRequireEnabled(): void
{
    if (empty(AVideoPlugin::loadPluginIfEnabled('LoginOIDC'))) {
        loginOidcShowError('LoginOIDC is disabled.');
    }
    try {
        $configured = LoginOIDC::settings()->isConfigured();
    } catch (InvalidSettingException $e) {
        loginOidcFail('invalid settings', $e);
    }
    if (!$configured) {
        loginOidcShowError('LoginOIDC is not configured.');
    }
}

/** 同じ接続元からの回数を、設定の上限で制限する。上限が 0 なら制限しない。 */
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
 * エラーの画面を出して止める。
 *
 * 本体の forbiddenPage() は使わない。未ログインの利用者をログイン画面へ飛ばし、
 * ログイン後に元の URL へ戻す作りのため、ログインの失敗に使うと、失敗した
 * コールバックへ戻ってきてしまう。
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
 * 失敗を記録して止める。
 *
 * 詳細はログにだけ出す。IdP の応答や検証の内容を含むことがあり、
 * 利用者の画面に出すと攻撃の手がかりになるため。画面には、ログの行を
 * 探すための番号だけを出す。
 */
function loginOidcFail(string $summary, \Throwable $e, string $userMessage = 'Sign-in failed. Please try again.'): never
{
    $reference = bin2hex(random_bytes(4));
    _error_log("LoginOIDC [{$reference}]: {$summary}: {$e->getMessage()}", AVideoLog::$ERROR);
    loginOidcShowError("{$userMessage} If the problem persists, contact the administrator with this reference: {$reference}");
}
