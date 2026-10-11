<?php
// SPDX-License-Identifier: MIT

// Shows the result of the settings check. Admins only. Opened from "Check settings" in the plugin list.

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
    // Contact the provider only when the settings could be read.
    $findings = [...$findings, ...SettingsReport::provider(LoginOIDC::settings(), RequestGuard::cache())];
    $checkedProvider = true;
} catch (InvalidSettingException) {
    // quick() has already reported why they could not be read.
}

$errors = count(array_filter($findings, static fn (Finding $f): bool => $f->level === Finding::ERROR));
$warnings = count($findings) - $errors;
$escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
// Translates and escapes for HTML.
$text = static fn (string $english, string|int ...$arguments): string => $escape(vsprintf(LoginOIDC::t($english), $arguments));
?>
<!DOCTYPE html>
<html lang="<?php echo $escape(getLanguage()); ?>">
<head>
    <meta charset="utf-8">
    <title><?php echo $text('LoginOIDC settings check'); ?></title>
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
    <h1><?php echo $text('LoginOIDC settings check'); ?></h1>
    <?php if ($findings === []) { ?>
        <p class="ok"><?php echo $text('No problems found.'); ?></p>
    <?php } else { ?>
        <p><span class="error"><?php echo $text('%d error(s)', $errors); ?></span>, <span class="warning"><?php echo $text('%d warning(s)', $warnings); ?></span></p>
        <table>
            <tr><th><?php echo $text('Level'); ?></th><th><?php echo $text('Setting'); ?></th><th><?php echo $text('What is wrong'); ?></th></tr>
            <?php foreach ($findings as $finding) { ?>
                <tr>
                    <td class="<?php echo $escape($finding->level); ?>"><?php echo $text($finding->level); ?></td>
                    <td><code><?php echo $escape($finding->setting); ?></code></td>
                    <td><?php echo $escape($finding->message(LoginOIDC::t(...))); ?></td>
                </tr>
            <?php } ?>
        </table>
    <?php } ?>
    <?php if (!$checkedProvider) { ?>
        <p><?php echo $text('The provider was not contacted because the settings could not be read.'); ?></p>
    <?php } ?>
    <dl>
        <dt><?php echo $text('Redirect URI to register at the provider'); ?></dt>
        <dd><code><?php echo $escape(LoginOIDC::callbackUrl()); ?></code></dd>
        <dt><?php echo $text('Back-channel logout URL'); ?></dt>
        <dd><code><?php echo $escape(LoginOIDC::backchannelLogoutUrl()); ?></code></dd>
    </dl>
</body>
</html>
