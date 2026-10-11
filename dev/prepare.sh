#!/bin/bash
# 開発環境を（再）インストールした後に流す。
#   - Encoder から AVideo への通信で、TLS の検証を切る（AVideo の証明書が自己署名のため）
#   - LoginOIDC を有効にし、テスト用の realm に向けた設定を入れる
#
#   KEYCLOAK_URL=http://<HOST>:8081 [SUDO=sudo] bash dev/prepare.sh
set -eu
: "${KEYCLOAK_URL:?KEYCLOAK_URL を設定する}"
AVIDEO_CONTAINER=${AVIDEO_CONTAINER:-avideo-avideo-1}
SUDO=${SUDO:-}

$SUDO docker exec "$AVIDEO_CONTAINER" bash -c '
cd /var/www/html/AVideo/Encoder/videos
if [ ! -f configuration.php ]; then
    echo "Encoder is not installed (see dev/README.md)" >&2; exit 1
fi
grep -q disableSSLVerification configuration.php \
  || sed -i "s|^require_once .*include_config.php.;|\$global[\"disableSSLVerification\"] = true;\n&|" configuration.php
php -l configuration.php >/dev/null'
$SUDO docker exec "$AVIDEO_CONTAINER" php /opt/loginoidc/dev/setup-plugin.php "$KEYCLOAK_URL" 2>&1 | grep -v 'AVideoLog::'
