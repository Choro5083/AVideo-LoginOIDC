<?php
// SPDX-License-Identifier: MIT

// Keycloak 側の変化（削除、無効化、権限の剥奪、属性の変更）を AVideo に反映する。
// cron から実行する。
//
//   php plugin/LoginOIDC/cli/sync.php [--dry-run] [--max-disable-ratio=<0-100>]
//
// 同期用の client のシークレットは、設定のファイルか、環境変数
// LOGINOIDC_SYNC_CLIENT_SECRET で渡す。
//
// 終了コード: 0 = 成功、1 = 失敗（何も変更していない）、2 = 使い方の誤り、3 = 別の同期が実行中

declare(strict_types=1);

use LoginOIDC\AVideo\DbException;
use LoginOIDC\AVideo\RequestGuard;
use LoginOIDC\Factory;
use LoginOIDC\InvalidSettingException;
use LoginOIDC\Oidc\OidcException;
use LoginOIDC\Sync\SyncException;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['dry-run', 'max-disable-ratio:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "usage: php sync.php [--dry-run] [--max-disable-ratio=<0-100>]\n");
    exit(0);
}
$maxRatio = null;
if (isset($options['max-disable-ratio'])) {
    $value = $options['max-disable-ratio'];
    if (!is_string($value) || preg_match('/^\d+$/', $value) !== 1 || (int) $value > 100) {
        fwrite(STDERR, "--max-disable-ratio must be an integer between 0 and 100\n");
        exit(2);
    }
    $maxRatio = (int) $value;
}

require_once __DIR__ . '/../../../videos/configuration.php';

$fail = static function (string $message, int $code = 1): never {
    _error_log("LoginOIDC sync: {$message}", AVideoLog::$ERROR);
    fwrite(STDERR, "LoginOIDC sync: {$message}\n");
    exit($code);
};

if (empty(AVideoPlugin::loadPluginIfEnabled('LoginOIDC'))) {
    $fail('the plugin is disabled');
}

// 前の実行が終わっていなければ、重ねて走らせない。
$lockFile = rtrim(sys_get_temp_dir(), '/') . '/loginoidc-sync-' . md5(__DIR__) . '.lock';
$lock = fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $fail('another sync is running', 3);
}

try {
    $settings = LoginOIDC::settings();
    if (!$settings->syncEnabled) {
        $fail('sync is not enabled in the plugin settings');
    }
    $report = Factory::synchronizer(
        $settings,
        RequestGuard::cache(),
        (string) (getenv('LOGINOIDC_SYNC_CLIENT_SECRET') ?: ''),
    )->run(isset($options['dry-run']), $maxRatio);
} catch (SyncException | InvalidSettingException | OidcException $e) {
    // ここに来るのは、変更を始める前に止まった場合だけ。
    $fail($e->getMessage() . ' (nothing was changed)');
} catch (DbException $e) {
    // 変更の途中で止まった可能性がある。次の実行で残りが反映される。
    $fail('database error, the sync may have stopped part-way: ' . $e->getMessage());
}

foreach (['disabled' => $report->disabled, 're-enabled' => $report->enabled, 'sessions revoked' => $report->sessionsRevoked] as $label => $names) {
    foreach ($names as $name) {
        fwrite(STDOUT, ($report->dryRun ? '[dry-run] ' : '') . "{$label}: {$name}\n");
        _error_log("LoginOIDC sync: " . ($report->dryRun ? '[dry-run] ' : '') . "{$label}: {$name}", AVideoLog::$SECURITY);
    }
}
if ($report->refusal !== null) {
    fwrite(STDOUT, "[dry-run] a real run would stop: {$report->refusal}\n");
}
fwrite(STDOUT, 'LoginOIDC sync: ' . $report->summary() . "\n");
_error_log('LoginOIDC sync: ' . $report->summary());
exit(0);
