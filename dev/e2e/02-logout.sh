#!/bin/bash
# Logout: RP-Initiated Logout and Back-Channel Logout
source "$(dirname "$0")/lib.sh"
reset_settings

echo "RP-initiated logout"
sso_login alice "$WORK/a" >/dev/null
out=$(curl -sk -c "$WORK/a" -b "$WORK/a" -o /dev/null -w '%{redirect_url}' "$AVIDEO_URL/logoff")
contains "logoff goes to the provider" "/protocol/openid-connect/logout" "$out"
contains "with an id_token_hint" "id_token_hint=" "$out"
check "the provider sends the browser back" "$AVIDEO_URL/" "$(curl -s -c "$WORK/a" -b "$WORK/a" -o /dev/null -w '%{redirect_url}' "$out")"
check "signed out of AVideo" no "$(logged_in "$WORK/a")"
location=$(curl -sk -c "$WORK/a" -b "$WORK/a" -o /dev/null -w '%{redirect_url}' "$AVIDEO_URL/plugin/LoginOIDC/login.php")
check "the provider asks for the password again" 200 "$(curl -s -c "$WORK/a" -b "$WORK/a" -o /dev/null -w '%{http_code}' "$location")"

echo "back-channel logout"
# Sessions of alice left in Keycloak by other scenarios would make it unclear which one is ended. Clear them first.
kc -X POST "$KC_ADMIN/users/$ALICE/logout" -o /dev/null; sleep 2
sso_login alice "$WORK/b1" >/dev/null; sso_login alice "$WORK/b2" >/dev/null; sso_login bob "$WORK/b3" >/dev/null
session=$(kc "$KC_ADMIN/users/$ALICE/sessions" | python3 -c "import json,sys;print(json.load(sys.stdin)[0]['id'])")
kc -X DELETE "$KC_ADMIN/sessions/$session" -o /dev/null; sleep 2
ended=$( [ "$(logged_in "$WORK/b1")" = no ] && echo 1 || echo 0 ); ended=$((ended + $( [ "$(logged_in "$WORK/b2")" = no ] && echo 1 || echo 0 )))
check "ending one provider session ends exactly one of alice's two sessions" 1 "$ended"
check "bob is not affected" yes "$(logged_in "$WORK/b3")"
kc -X POST "$KC_ADMIN/users/$ALICE/logout" -o /dev/null; sleep 2
check "logging alice out everywhere ends her first session" no "$(logged_in "$WORK/b1")"
check "and her second session" no "$(logged_in "$WORK/b2")"
check "bob is still not affected" yes "$(logged_in "$WORK/b3")"

echo "the back-channel endpoint rejects forged requests"
check "GET" 400 "$(curl -sk -o /dev/null -w '%{http_code}' "$AVIDEO_URL/plugin/LoginOIDC/backchannelLogout.php")"
check "a token that is not a JWT" 400 "$(curl -sk -o /dev/null -w '%{http_code}' -d logout_token=forged "$AVIDEO_URL/plugin/LoginOIDC/backchannelLogout.php")"
check "bob is still signed in after the forged requests" yes "$(logged_in "$WORK/b3")"
finish
