#!/bin/bash
# Native sign-in control, restoration from the cookie, the Encoder exception
source "$(dirname "$0")/lib.sh"
reset_settings
sso_login alice "$WORK/setup-alice" >/dev/null; sso_login bob "$WORK/setup-bob" >/dev/null; sso_login carol "$WORK/setup-carol" >/dev/null
ADMIN_PASSWORD=$(env_value SYSTEM_ADMIN_PASSWORD)
native() { curl -sk -c "$3" -b "$3" --data-urlencode "user=$1" --data-urlencode "pass=$2" "${@:4}" "$AVIDEO_URL/objects/login.json.php" \
    | python3 -c "import json,sys;print(json.load(sys.stdin).get('isLogged'))" 2>/dev/null; }
# Signs in with a token from the Encoder (the same container as AVideo)
from_encoder() { dexec "$AVIDEO_CONTAINER" curl -sk "$AVIDEO_URL/login?user=$1&pass=$2&encodedPass=1" \
    | python3 -c "import json,sys;print(json.load(sys.stdin).get('isLogged'))" 2>/dev/null; }
from_outside() { curl -sk -A AVideoEncoder "$AVIDEO_URL/login?user=$1&pass=$2&encodedPass=1" \
    | python3 -c "import json,sys;print(json.load(sys.stdin).get('isLogged'))" 2>/dev/null; }
encoder_post() { dexec "$AVIDEO_CONTAINER" curl -sk -F format=mp4 -F "user=$1" -F "password=$2" -F title=e2e "$AVIDEO_URL/aVideoEncoder.json"; }

echo "modes"
setting nativeLoginMode allow
check "allow: admin signs in with the password" True "$(native admin "$ADMIN_PASSWORD" "$WORK/n1")"
setting nativeLoginMode allowlist; setting nativeLoginAllowlist ""
check "allowlist (empty): admin is denied" False "$(native admin "$ADMIN_PASSWORD" "$WORK/n2")"
check "and has no session" no "$(logged_in "$WORK/n2")"
setting nativeLoginAllowlist "admin"
check "allowlist (admin): admin signs in" True "$(native admin "$ADMIN_PASSWORD" "$WORK/n3")"
sso_login bob "$WORK/n4" >/dev/null
check "allowlist: sign-in through the provider still works" yes "$(logged_in "$WORK/n4")"

echo "Encoder exception (allowlist mode)"
BOB_TOKEN=$(user_token bob); CAROL_TOKEN=$(user_token carol)
setting encoderAddresses ""
check "no Encoder address: the Encoder is denied" False "$(from_encoder bob "$BOB_TOKEN")"
contains "and cannot deliver a video" "refused access" "$(encoder_post bob "$BOB_TOKEN")"
setting encoderAddresses "127.0.0.1"
check "Encoder address set: the Encoder signs in" True "$(from_encoder bob "$BOB_TOKEN")"
contains "and passes the upload permission check" '"error":false' "$(encoder_post bob "$BOB_TOKEN" | tr -d ' ')"
contains "a viewer still cannot upload through the Encoder" "refused access" "$(encoder_post carol "$CAROL_TOKEN")"
check "the same token from another address is denied, even with the Encoder's User-Agent" False "$(from_outside bob "$BOB_TOKEN")"
check "a wrong token from the Encoder is denied" False "$(from_encoder bob _user_hash_invalid)"
db "delete from videos where title='e2e'" 2>/dev/null

echo "Encoder exception behind a reverse proxy (this host plays the proxy)"
# Requests from this host reach AVideo from the gateway of the container's network. Here that address
# plays a reverse proxy, and 192.0.2.10 an Encoder behind it.
PROXY=$(host_address)
via_proxy() { curl -sk -H "X-Forwarded-For: $3" -H "X-Real-IP: $3" "$AVIDEO_URL/login?user=$1&pass=$2&encodedPass=1" \
    | python3 -c "import json,sys;print(json.load(sys.stdin).get('isLogged'))" 2>/dev/null; }
setting encoderAddresses "192.0.2.10"
trusted_proxies ""
check "no trusted proxy: the forwarded Encoder address is ignored" False "$(via_proxy bob "$BOB_TOKEN" 192.0.2.10)"
trusted_proxies "$PROXY"
check "trusted proxy: the Encoder behind it signs in" True "$(via_proxy bob "$BOB_TOKEN" 192.0.2.10)"
check "trusted proxy: another address behind it is denied" False "$(via_proxy bob "$BOB_TOKEN" 192.0.2.11)"
check "trusted proxy: a request from the proxy itself is denied" False "$(from_outside bob "$BOB_TOKEN")"
quick_check() { dexec "$AVIDEO_CONTAINER" php -r '
require "/var/www/html/AVideo/videos/configuration.php";
foreach (LoginOIDC\Admin\SettingsReport::quick(fn () => LoginOIDC::settings()) as $f) { echo "{$f->level} {$f->setting}\n"; }' 2>/dev/null; }
setting encoderAddresses "$PROXY"
contains "the settings check reports an Encoder address that covers the proxy" "error encoderAddresses" "$(quick_check)"
trusted_proxies ""

echo "cookie restoration (allow mode)"
setting nativeLoginMode allow; setting encoderAddresses ""
curl -sk -c "$WORK/c1" -b "$WORK/c1" -o /dev/null "$AVIDEO_URL/login?user=bob&pass=$BOB_TOKEN&encodedPass=1&rememberme=true"
contains "a linked user gets the remember-me cookie from AVideo" credentials "$(cat "$WORK/c1")"
check "and is signed in" yes "$(logged_in "$WORK/c1")"
drop_session_cookie "$WORK/c1" "$WORK/c2"
check "a linked user is not restored from the cookie" no "$(logged_in "$WORK/c2")"
native admin "$ADMIN_PASSWORD" "$WORK/c3" --data-urlencode rememberme=true >/dev/null
drop_session_cookie "$WORK/c3" "$WORK/c4"
check "an unlinked user is restored as before (AVideo's own behaviour)" yes "$(logged_in "$WORK/c4")"

echo "native sessions of linked users are watched too"
curl -sk -c "$WORK/s1" -b "$WORK/s1" -o /dev/null "$AVIDEO_URL/login?user=bob&pass=$BOB_TOKEN&encodedPass=1"
check "bob has a native session" yes "$(logged_in "$WORK/s1")"
db "update users set status='i' where user='bob'"
check "it ends as soon as the user is disabled" no "$(logged_in "$WORK/s1")"
db "update users set status='a' where user='bob'"
reset_settings
finish
