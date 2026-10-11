#!/bin/bash
# 管理画面: 設定の検査と、シークレットの暗号化
source "$(dirname "$0")/lib.sh"
reset_settings
ADMIN_PASSWORD=$(env_value SYSTEM_ADMIN_PASSWORD)
curl -sk -c "$WORK/admin" -b "$WORK/admin" -o /dev/null --data-urlencode user=admin --data-urlencode "pass=$ADMIN_PASSWORD" "$AVIDEO_URL/objects/login.json.php"
check_page() { curl -sk -b "$WORK/admin" "$AVIDEO_URL/plugin/LoginOIDC/check.php"; }
# 本体は、同じサイトからのリクエストかを Referer で確かめる
plugin_list() { curl -sk -b "$WORK/admin" -H "Referer: $AVIDEO_URL/plugins" "$AVIDEO_URL/objects/pluginsAvailable.json.php" \
    | python3 -c "import json,sys
rows=json.load(sys.stdin).get('rows',[])
print(next((r.get('description','') + ' ' + str(r.get('pluginMenu','')) for r in rows if r.get('name')=='LoginOIDC'), 'not-found'))"; }

echo "the client secret is encrypted at rest"
stored=$(db "select object_data from plugins where name='LoginOIDC'")
check "the stored settings do not contain the secret in clear text" 0 "$(echo "$stored" | grep -c 'dev-only-avideo-secret')"
sso_login alice "$WORK/alice" >/dev/null
check "sign-in still works, so the secret is decrypted when used" yes "$(logged_in "$WORK/alice")"

echo "check page"
page=$(check_page)
contains "an admin can open it" "LoginOIDC settings check" "$page"
contains "the development settings produce the http warning" "allowInsecureHttp" "$page"
check "and no errors" 1 "$(echo "$page" | grep -c '0 error(s)')"
contains "it shows the redirect URI to register" "/plugin/LoginOIDC/callback.php" "$page"
sso_login carol "$WORK/carol" >/dev/null
check "a non-admin cannot open it" 0 "$(curl -sk -b "$WORK/carol" "$AVIDEO_URL/plugin/LoginOIDC/check.php" | grep -c 'LoginOIDC settings check')"
check "nor an anonymous visitor" 0 "$(curl -sk "$AVIDEO_URL/plugin/LoginOIDC/check.php" | grep -c 'LoginOIDC settings check')"
contains "the plugin list offers the check" "Check settings" "$(plugin_list)"

echo "a broken mapping"
setting flagMapping '{"avideo-admins": '
page=$(check_page)
contains "the check page names the setting" "<code>flagMapping</code>" "$page"
contains "and says it is not valid JSON" "not valid JSON" "$page"
contains "the plugin list flags it right after saving" "error(s) in the settings" "$(plugin_list)"
sso_login bob "$WORK/bob" >/dev/null
check "sign-in is refused rather than run with broken settings" no "$(logged_in "$WORK/bob")"
check "an existing session is not thrown out by the broken settings" yes "$(logged_in "$WORK/alice")"

echo "an unreachable provider"
reset_settings; setting issuer "${KEYCLOAK_URL}/realms/does-not-exist"
contains "the check page reports it" "cannot be reached or is misconfigured" "$(check_page)"

echo "a risky but valid configuration"
reset_settings; setting flagMapping '{"avideo-uploaders": ["canUpload"]}'
contains "warns that isAdmin would be removed from everyone" "No value maps to isAdmin" "$(check_page)"
reset_settings
finish
