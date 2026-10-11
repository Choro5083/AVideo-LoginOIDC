#!/bin/bash
# Run after (re)installing the development environment.
#   - turns off TLS verification from the Encoder to AVideo (AVideo's certificate is self-signed)
#   - enables LoginOIDC and points it at the test realm
#
#   KEYCLOAK_URL=http://<HOST>:8081 [SUDO=sudo] bash dev/prepare.sh
set -eu
: "${KEYCLOAK_URL:?set KEYCLOAK_URL}"
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
