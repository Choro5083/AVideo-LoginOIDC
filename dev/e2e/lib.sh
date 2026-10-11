# 結合テストの共通部分。各シナリオから source する。
#
# 必要な環境変数:
#   AVIDEO_URL        例: https://avideo.example.test
#   KEYCLOAK_URL      例: http://avideo.example.test:8081
#   AVIDEO_ENV_FILE   AVideo の .env（管理者のパスワードを読む）。読むのに sudo が要るなら SUDO=sudo
# 任意:
#   AVIDEO_CONTAINER  既定 avideo-avideo-1
#   DB_CONTAINER      既定 avideo-database-1

: "${AVIDEO_URL:?AVIDEO_URL を設定する}" "${KEYCLOAK_URL:?KEYCLOAK_URL を設定する}" "${AVIDEO_ENV_FILE:?AVIDEO_ENV_FILE を設定する}"
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

# check <説明> <期待する値> <実際の値>
check() {
    if [ "$2" = "$3" ]; then
        echo "  ok    $1"
    else
        echo "  FAIL  $1 (expected: $2, actual: $3)"; FAILED=$((FAILED + 1))
    fi
}
# contains <説明> <含まれるべき文字列> <実際の値>
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

# プラグインの設定を1つ変える。setting <項目> <値>
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
# 設定を、開発環境の既定（dev/setup-plugin.php）に戻す
reset_settings() { dexec "$AVIDEO_CONTAINER" php /opt/loginoidc/dev/setup-plugin.php "$KEYCLOAK_URL" >/dev/null 2>&1; }

db() { dexec "$DB_CONTAINER" mariadb -uavideo -pavideo avideo -N -e "$1"; }
user_field() { db "select $2 from users where user='$1'"; }

# ブラウザと同じ手順で SSO ログインする。sso_login <ユーザ> <Cookie のファイル> [開始する URL]
# 最後のリダイレクト先を出力する。
sso_login() {
    local jar=$2 start=${3:-$AVIDEO_URL/plugin/LoginOIDC/login.php} location action callback
    location=$(curl -sk -c "$jar" -b "$jar" -o /dev/null -w '%{redirect_url}' -H 'Accept: text/html' "$start")
    [ -n "$location" ] || { echo "not-started"; return; }
    # 自動リダイレクトから始めた場合は、プラグインの入口を1つ挟む
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
# ログインしているか（ページにログアウトのリンクがあるか）。logged_in <Cookie のファイル>
logged_in() { [ "$(curl -sk -c "$1" -b "$1" "$AVIDEO_URL/" | grep -c -i logoff)" -gt 0 ] && echo yes || echo no; }

# セッションの Cookie だけを捨てた Cookie のファイルを作る（ログイン状態を復元する Cookie は残す）
drop_session_cookie() {
    local name
    name=$(sed 's/^#HttpOnly_//' "$1" | awk '!/^#/ && NF>=7 && $6 ~ /^[0-9a-f]{32}$/ {print $6}' | head -1)
    grep -v -e "$name" -e PHPSESSID "$1" > "$2"
}

# Keycloak の管理者として API を呼ぶ。kc <curl の引数...>
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

# Encoder が AVideo から受け取るのと同じ、期限付きのトークンを作る。user_token <ユーザ>
user_token() {
    dexec "$AVIDEO_CONTAINER" php -r '
require "/var/www/html/AVideo/videos/configuration.php";
$r = $global["mysqli"]->query("select id from users where user=\"" . $global["mysqli"]->real_escape_string($argv[1]) . "\"");
echo User::getUserHash((int) $r->fetch_row()[0]);' "$1" 2>/dev/null | tail -1
}

# 同期を実行する。出力の最後の行に終了コードを付ける。run_sync [引数...]
run_sync() {
    local out
    out=$(dexec -u www-data -e "LOGINOIDC_SYNC_CLIENT_SECRET=${SYNC_SECRET:-dev-only-sync-secret}" "$AVIDEO_CONTAINER" \
        php /var/www/html/AVideo/plugin/LoginOIDC/cli/sync.php "$@" 2>&1 | grep -v 'AVideoLog::'; echo "exit=${PIPESTATUS[0]}")
    echo "$out"
}
