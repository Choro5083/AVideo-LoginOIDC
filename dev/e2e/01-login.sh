#!/bin/bash
# ログイン: ユーザの作成、権限の反映、コールバックの再送の拒否、戻り先
source "$(dirname "$0")/lib.sh"
reset_settings

echo "first sign-in creates the users and applies the flags"
for user in alice bob carol dave; do
    sso_login $user "$WORK/$user" >/dev/null
    check "$user is signed in" yes "$(logged_in "$WORK/$user")"
done
check "alice is admin" 1 "$(user_field alice isAdmin)"
check "bob can upload" 1 "$(user_field bob canUpload)"
check "bob is not admin" 0 "$(user_field bob isAdmin)"
check "carol cannot upload" 0 "$(user_field carol canUpload)"
check "dave's e-mail is not verified" 0 "$(user_field dave emailVerified)"
check "alice's e-mail is verified" 1 "$(user_field alice emailVerified)"

echo "a second sign-in reuses the user"
before=$(db "select count(*) from users")
sso_login alice "$WORK/alice-again" >/dev/null
check "no new user" "$before" "$(db "select count(*) from users")"

echo "the callback cannot be replayed"
jar=$WORK/replay
location=$(curl -sk -c $jar -b $jar -o /dev/null -w '%{redirect_url}' "$AVIDEO_URL/plugin/LoginOIDC/login.php")
curl -s -c $jar -b $jar -o "$WORK/form.html" "$location"
action=$(grep -o 'action="[^"]*"' "$WORK/form.html" | head -1 | sed 's/action="//;s/"$//;s/&amp;/\&/g')
callback=$(curl -s -c $jar -b $jar -o /dev/null -w '%{redirect_url}' --data-urlencode username=carol --data-urlencode password=carol "$action")
check "first use succeeds" 302 "$(curl -sk -c $jar -b $jar -o /dev/null -w '%{http_code}' "$callback")"
check "second use is rejected" 403 "$(curl -sk -c $jar -b $jar -o /dev/null -w '%{http_code}' "$callback")"
check "a callback without a pending sign-in is rejected" 403 \
    "$(curl -sk -o /dev/null -w '%{http_code}' "$AVIDEO_URL/plugin/LoginOIDC/callback.php?state=x&code=y")"

echo "return address"
check "a relative return address is honoured" "$AVIDEO_URL/channels?a=1&b=2" \
    "$(sso_login carol "$WORK/ret1" "$AVIDEO_URL/plugin/LoginOIDC/login.php?returnTo=%2Fchannels%3Fa%3D1%26b%3D2")"
check "an external return address is ignored" "$AVIDEO_URL/" \
    "$(sso_login carol "$WORK/ret2" "$AVIDEO_URL/plugin/LoginOIDC/login.php?returnTo=https%3A%2F%2Fevil.example.com%2F")"

echo "required claim"
setting requiredClaimPath groups; setting requiredClaimValues "avideo-viewers,avideo-admins"
sso_login dave "$WORK/dave-denied" >/dev/null
check "dave (no group) is denied" no "$(logged_in "$WORK/dave-denied")"
contains "the page says the account is not allowed" "not allowed to sign in" "$(cat "$WORK/callback.html")"
sso_login carol "$WORK/carol-allowed" >/dev/null
check "carol (viewer) is allowed" yes "$(logged_in "$WORK/carol-allowed")"
reset_settings
finish
