<?php
// SPDX-License-Identifier: MIT

// 設定の検査結果を出すページ。管理者だけが開ける。プラグインの一覧の「Check settings」から開く。

declare(strict_types=1);

use LoginOIDC\Admin\Finding;
use LoginOIDC\Admin\SettingsReport;
use LoginOIDC\AVideo\RequestGuard;
use LoginOIDC\InvalidSettingException;

require_once __DIR__ . '/../../videos/configuration.php';

if (!User::isAdmin()) {
    forbiddenPage('Admin only');
}
if (empty(AVideoPlugin::loadPlugin('LoginOIDC'))) {
    forbiddenPage('LoginOIDC is not available');
}

$findings = SettingsReport::quick(LoginOIDC::settings(...));
$checkedProvider = false;
try {
    // IdP との通信は、設定が読めたときだけ試す。
    $findings = [...$findings, ...SettingsReport::provider(LoginOIDC::settings(), RequestGuard::cache())];
    $checkedProvider = true;
} catch (InvalidSettingException) {
    // 読めない理由は quick() がすでに入れている。
}

$errors = count(array_filter($findings, static fn (Finding $f): bool => $f->level === Finding::ERROR));
$warnings = count($findings) - $errors;
$escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>LoginOIDC settings check</title>
    <style>
        body { font-family: sans-serif; margin: 1.5em; color: #222; }
        h1 { font-size: 1.3em; }
        table { border-collapse: collapse; width: 100%; }
        th, td { text-align: left; padding: .5em .7em; border-bottom: 1px solid #ddd; vertical-align: top; }
        .error { color: #a40000; font-weight: bold; }
        .warning { color: #8a5a00; font-weight: bold; }
        .ok { color: #1a6b1a; font-weight: bold; }
        code { background: #f2f2f2; padding: .1em .3em; }
        dl { margin-top: 1.5em; } dt { font-weight: bold; margin-top: .6em; }
    </style>
</head>
<body>
    <h1>LoginOIDC settings check</h1>
    <?php if ($findings === []) { ?>
        <p class="ok">No problems found.</p>
    <?php } else { ?>
        <p><span class="error"><?php echo $errors; ?> error(s)</span>, <span class="warning"><?php echo $warnings; ?> warning(s)</span></p>
        <table>
            <tr><th>Level</th><th>Setting</th><th>What is wrong</th></tr>
            <?php foreach ($findings as $finding) { ?>
                <tr>
                    <td class="<?php echo $escape($finding->level); ?>"><?php echo $escape($finding->level); ?></td>
                    <td><code><?php echo $escape($finding->setting); ?></code></td>
                    <td><?php echo $escape($finding->message); ?></td>
                </tr>
            <?php } ?>
        </table>
    <?php } ?>
    <?php if (!$checkedProvider) { ?>
        <p>The provider was not contacted because the settings could not be read.</p>
    <?php } ?>
    <dl>
        <dt>Redirect URI to register at the provider</dt>
        <dd><code><?php echo $escape(LoginOIDC::callbackUrl()); ?></code></dd>
        <dt>Back-channel logout URL</dt>
        <dd><code><?php echo $escape(LoginOIDC::backchannelLogoutUrl()); ?></code></dd>
    </dl>
</body>
</html>
