#!/bin/bash
# 未ログイン時の自動リダイレクト
source "$(dirname "$0")/lib.sh"
reset_settings
HTML='Accept: text/html,application/xhtml+xml'
probe() { curl -sk -o /dev/null -w '%{http_code} %{redirect_url}' "${@:2}" "$AVIDEO_URL$1"; }

check "off: the top page is served" "200 " "$(probe / -H "$HTML")"
setting autoRedirect true
contains "on: a page view goes to the plugin's sign-in" "/plugin/LoginOIDC/login.php?returnTo=%2F" "$(probe / -H "$HTML")"
check "on: a request without Accept is left alone" "200 " "$(probe /)"
check "on: AVideo's own sign-in page stays reachable" "200 " "$(probe /user -H "$HTML")"
check "on: a request from JavaScript is left alone" "200 " "$(probe / -H "$HTML" -H 'X-Requested-With: XMLHttpRequest')"
check "on: after signing in, the browser returns to the page it asked for" "$AVIDEO_URL/channels?a=1&b=2" \
    "$(sso_login carol "$WORK/j" "$AVIDEO_URL/channels?a=1&b=2")"
check "on: a signed-in user is not redirected" "200 " "$(probe / -H "$HTML" -b "$WORK/j")"
reset_settings
finish
