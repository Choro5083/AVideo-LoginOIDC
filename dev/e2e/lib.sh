# Shared part of the end-to-end tests. Each scenario sources it.
#
# Required environment variables:
#   AVIDEO_URL        for example https://avideo.example.test
#   KEYCLOAK_URL      for example http://avideo.example.test:8081
#   AVIDEO_ENV_FILE   AVideo's .env (the admin passwords are read from it). Set SUDO=sudo if reading it needs sudo
# Optional:
#   AVIDEO_CONTAINER  default avideo-avideo-1
#   DB_CONTAINER      default avideo-database-1

: "${AVIDEO_URL:?set AVIDEO_URL}" "${KEYCLOAK_URL:?set KEYCLOAK_URL}" "${AVIDEO_ENV_FILE:?set AVIDEO_ENV_FILE}"
AVIDEO_CONTAINER=${AVIDEO_CONTAINER:-avideo-avideo-1}
DB_CONTAINER=${DB_CONTAINER:-avideo-database-1}
SUDO=${SUDO:-}
REALM=loginoidc-test
KC_ADMIN=$KEYCLOAK_URL/admin/realms/$REALM
WORK=$(mktemp -d); trap 'rm -rf "$WORK"' EXIT
FAILED=0

ALICE=a11ce000-0000-4000-8000-000000000001
BOB=b0b00000-0000-4000-8000-000000000002
CAROL=ca401000-0000-4000-8000-000000000003
DAVE=da7e0000-0000-4000-8000-000000000004

dexec() { $SUDO docker exec "$@"; }
env_value() { $SUDO grep "^$1=" "$AVIDEO_ENV_FILE" | cut -d= -f2-; }

# check <description> <expected> <actual>
check() {
    if [ "$2" = "$3" ]; then
        echo "  ok    $1"
    else
        echo "  FAIL  $1 (expected: $2, actual: $3)"; FAILED=$((FAILED + 1))
    fi
}
# contains <description> <expected substring> <actual>
contains() {
    case "$3" in
        *"$2"*) echo "  ok    $1" ;;
        *) echo "  FAIL  $1 (expected to contain: $2, actual: ${3:0:160})"; FAILED=$((FAILED + 1)) ;;
    esac
}
finish() {
    if [ "$FAILED" -eq 0 ]; then echo "PASS $(basename "$0")"; else echo "FAILED $(basename "$0"): $FAILED"; fi
    exit "$FAILED"
}

# Changes one plugin setting. setting <name> <value>
setting() {
    dexec "$AVIDEO_CONTAINER" php -r '
require "/var/www/html/AVideo/videos/configuration.php";
$p = AVideoPlugin::loadPlugin("LoginOIDC"); $o = $p->getDataObject();
$n = $argv[1]; $v = $argv[2];
if (is_object($o->$n)) { $o->$n->value = $v; }
elseif (is_bool($o->$n)) { $o->$n = $v === "true"; }
else { $o->$n = $v; }
$p->setDataObject($o);' "$1" "$2" >/dev/null 2>&1
}
# Puts the settings back to the development defaults (dev/setup-plugin.php)
reset_settings() { dexec "$AVIDEO_CONTAINER" php /opt/loginoidc/dev/setup-plugin.php "$KEYCLOAK_URL" >/dev/null 2>&1; }

db() { dexec "$DB_CONTAINER" mariadb -uavideo -pavideo avideo -N -e "$1"; }
user_field() { db "select $2 from users where user='$1'"; }

# Signs in through the provider the way a browser does. sso_login <user> <cookie file> [start URL]
# Prints where the last redirect points.
sso_login() {
    local jar=$2 start=${3:-$AVIDEO_URL/plugin/LoginOIDC/login.php} location action callback
    location=$(curl -sk -c "$jar" -b "$jar" -o /dev/null -w '%{redirect_url}' -H 'Accept: text/html' "$start")
    [ -n "$location" ] || { echo "not-started"; return; }
    # When started from the automatic redirect, there is one more hop through the plugin's entry point
    case "$location" in *"/plugin/LoginOIDC/login.php"*)
        location=$(curl -sk -c "$jar" -b "$jar" -o /dev/null -w '%{redirect_url}' "$location") ;;
    esac
    curl -s -c "$jar" -b "$jar" -o "$WORK/form.html" "$location"
    action=$(grep -o 'action="[^"]*"' "$WORK/form.html" | head -1 | sed 's/action="//;s/"$//;s/&amp;/\&/g')
    [ -n "$action" ] || { echo "no-login-form"; return; }
    callback=$(curl -s -c "$jar" -b "$jar" -o /dev/null -w '%{redirect_url}' \
        --data-urlencode "username=$1" --data-urlencode "password=$1" "$action")
    [ -n "$callback" ] || { echo "rejected-by-keycloak"; return; }
    curl -sk -c "$jar" -b "$jar" -o "$WORK/callback.html" -w '%{redirect_url}' "$callback"
}
# Whether the session is signed in (the page has a logout link). logged_in <cookie file>
logged_in() { [ "$(curl -sk -c "$1" -b "$1" "$AVIDEO_URL/" | grep -c -i logoff)" -gt 0 ] && echo yes || echo no; }

# Makes a cookie file without the session cookie (the remember-me cookie stays)
drop_session_cookie() {
    local name
    name=$(sed 's/^#HttpOnly_//' "$1" | awk '!/^#/ && NF>=7 && $6 ~ /^[0-9a-f]{32}$/ {print $6}' | head -1)
    grep -v -e "$name" -e PHPSESSID "$1" > "$2"
}

# Calls the Keycloak API as its admin. kc <curl arguments...>
kc() {
    if [ -z "${KC_TOKEN:-}" ]; then
        KC_TOKEN=$(curl -s -d grant_type=password -d client_id=admin-cli -d username=admin \
            --data-urlencode "password=$(env_value KEYCLOAK_ADMIN_PASSWORD)" \
            "$KEYCLOAK_URL/realms/master/protocol/openid-connect/token" \
            | python3 -c "import json,sys;print(json.load(sys.stdin)['access_token'])")
    fi
    curl -s -H "Authorization: Bearer $KC_TOKEN" -H 'Content-Type: application/json' "$@"
}
kc_group_id() { kc "$KC_ADMIN/groups?search=$1&exact=true" | python3 -c "import json,sys;print(json.load(sys.stdin)[0]['id'])"; }

# Makes the same time-limited token the Encoder receives from AVideo. user_token <user>
user_token() {
    dexec "$AVIDEO_CONTAINER" php -r '
require "/var/www/html/AVideo/videos/configuration.php";
$r = $global["mysqli"]->query("select id from users where user=\"" . $global["mysqli"]->real_escape_string($argv[1]) . "\"");
echo User::getUserHash((int) $r->fetch_row()[0]);' "$1" 2>/dev/null | tail -1
}

# Sets AVideo's $global['trustedProxies'] to one address, or removes it. trusted_proxies [address]
# Written just above the bootstrap line of configuration.php, between marker comments, so it can be removed again.
trusted_proxies() {
    local conf=/var/www/html/AVideo/videos/configuration.php
    dexec "$AVIDEO_CONTAINER" sed -i '/^\/\/ e2e-trusted-proxies begin$/,/^\/\/ e2e-trusted-proxies end$/d' "$conf"
    if [ -n "${1:-}" ]; then
        dexec "$AVIDEO_CONTAINER" sed -i \
            "s|^// Do not change the bootstrap below\.|// e2e-trusted-proxies begin\n\$global['trustedProxies'] = ['$1'];\n// e2e-trusted-proxies end\n&|" "$conf"
    fi
    # PHP's opcache keeps serving the old configuration.php for up to opcache.revalidate_freq
    # seconds (2 by default) after it changes. Wait that long before the next request.
    sleep 3
}
# The address AVideo sees for requests from this host (the gateway of the container's network)
host_address() { $SUDO docker inspect -f '{{range .NetworkSettings.Networks}}{{.Gateway}} {{end}}' "$AVIDEO_CONTAINER" | awk '{print $1}'; }

# Runs the sync. The exit code is appended as the last line. run_sync [arguments...]
run_sync() {
    local out
    out=$(dexec -u www-data -e "LOGINOIDC_SYNC_CLIENT_SECRET=${SYNC_SECRET:-dev-only-sync-secret}" "$AVIDEO_CONTAINER" \
        php /var/www/html/AVideo/plugin/LoginOIDC/cli/sync.php "$@" 2>&1 | grep -v 'AVideoLog::'; echo "exit=${PIPESTATUS[0]}")
    echo "$out"
}
