<?php
// SPDX-License-Identifier: MIT

// 開発環境で LoginOIDC を有効にし、テスト用の realm に向けた設定を入れる。
// avideo のコンテナの中で実行する（dev/README.md）。
//
//   php /opt/loginoidc/dev/setup-plugin.php <Keycloak の URL>

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$keycloakUrl = rtrim($argv[1] ?? '', '/');
if ($keycloakUrl === '') {
    fwrite(STDERR, "使い方: php setup-plugin.php <Keycloak の URL>\n");
    exit(2);
}

require_once '/var/www/html/AVideo/videos/configuration.php';
require_once $global['systemRootPath'] . 'objects/plugin.php';
require_once $global['systemRootPath'] . 'plugin/AVideoPlugin.php';

$plugin = AVideoPlugin::loadPlugin('LoginOIDC');
if (!is_object($plugin)) {
    fwrite(STDERR, "LoginOIDC を読み込めない。plugin/LoginOIDC にマウントされているか確認する\n");
    exit(1);
}

$row = new Plugin(0);
$row->loadFromUUID($plugin->getUUID());
$row->setName($plugin->getName());
$row->setDirName('LoginOIDC');
$row->setStatus('active');
$row->setPluginversion($plugin->getPluginVersion());
if (empty($row->save())) {
    fwrite(STDERR, "プラグインを有効にできなかった\n");
    exit(1);
}

// 戻り値は見ない。作った直後は本体のキャッシュが古いままで false が返るため、
// テーブルがあるかは自分で確かめる。
AVideoPlugin::isPluginTablesInstalled('LoginOIDC', true);
foreach (['login_oidc_identities', 'login_oidc_sessions'] as $table) {
    $found = $global['mysqli']->query("SHOW TABLES LIKE '{$table}'");
    if ($found === false || $found->num_rows !== 1) {
        fwrite(STDERR, "テーブル {$table} を作れなかった\n");
        exit(1);
    }
}

$data = $plugin->getEmptyDataObject();
$data->issuer = $keycloakUrl . '/realms/loginoidc-test';
$data->clientId = 'avideo';
$data->clientSecret = 'dev-only-avideo-secret';
// 開発環境の Keycloak は http で動かしている。
$data->allowInsecureHttp = true;
$data->rolesClaimPath = 'resource_access.avideo.roles';
$data->managedFlags = 'isAdmin, canUpload';
$data->flagMapping->value = '{"admin": ["isAdmin"], "uploader": ["canUpload"]}';
if (empty($plugin->setDataObject($data))) {
    fwrite(STDERR, "設定を保存できなかった\n");
    exit(1);
}

echo "LoginOIDC を有効にした。issuer = {$data->issuer}\n";
