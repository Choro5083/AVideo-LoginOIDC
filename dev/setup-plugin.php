<?php
// SPDX-License-Identifier: MIT

// Enables LoginOIDC in the development environment and points it at the test realm.
// Run it inside the avideo container (see dev/README.md).
//
//   php /opt/loginoidc/dev/setup-plugin.php <Keycloak URL>

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$keycloakUrl = rtrim($argv[1] ?? '', '/');
if ($keycloakUrl === '') {
    fwrite(STDERR, "usage: php setup-plugin.php <Keycloak URL>\n");
    exit(2);
}

require_once '/var/www/html/AVideo/videos/configuration.php';
require_once $global['systemRootPath'] . 'objects/plugin.php';
require_once $global['systemRootPath'] . 'plugin/AVideoPlugin.php';

$plugin = AVideoPlugin::loadPlugin('LoginOIDC');
if (!is_object($plugin)) {
    fwrite(STDERR, "cannot load LoginOIDC. Check that it is mounted at plugin/LoginOIDC\n");
    exit(1);
}

$row = new Plugin(0);
$row->loadFromUUID($plugin->getUUID());
$row->setName($plugin->getName());
$row->setDirName('LoginOIDC');
$row->setStatus('active');
$row->setPluginversion($plugin->getPluginVersion());
if (empty($row->save())) {
    fwrite(STDERR, "could not enable the plugin\n");
    exit(1);
}

// The return value is ignored: right after creating the tables AVideo's cache is stale and it returns false.
// Whether the tables exist is checked here instead.
AVideoPlugin::isPluginTablesInstalled('LoginOIDC', true);
foreach (['login_oidc_identities', 'login_oidc_sessions'] as $table) {
    $found = $global['mysqli']->query("SHOW TABLES LIKE '{$table}'");
    if ($found === false || $found->num_rows !== 1) {
        fwrite(STDERR, "could not create table {$table}\n");
        exit(1);
    }
}

// Right after enabling, AVideo may have cached the plugin row as missing. Make it read again.
Plugin::getPluginByUUID($plugin->getUUID(), true);

$data = $plugin->getEmptyDataObject();
$data->issuer = $keycloakUrl . '/realms/loginoidc-test';
$data->clientId = 'avideo';
$data->clientSecret->value = 'dev-only-avideo-secret';
// Keycloak runs over http in the development environment.
$data->allowInsecureHttp = true;
// The end-to-end tests sign in dozens of times from one address in a short time.
$data->loginRateLimit = 0;
// Permissions come from groups. The sync client can read groups with view-users alone,
// so sign-in (claims) and sync (Admin API) work from the same values.
$data->rolesClaimPath = 'groups';
$data->managedFlags = 'isAdmin, canUpload';
$data->flagMapping->value = '{"avideo-admins": ["isAdmin"], "avideo-uploaders": ["canUpload"]}';
$data->syncEnabled = true;
$data->syncClientId = 'avideo-sync';
$data->syncRoleSource->value = 'groups';
// Encrypt the encrypted settings before saving, as saving from the admin page does.
if (empty($plugin->setDataObject(Plugin::encryptIfNeed($data)))) {
    fwrite(STDERR, "could not save the settings\n");
    exit(1);
}

echo "LoginOIDC is enabled. issuer = {$data->issuer}\n";
