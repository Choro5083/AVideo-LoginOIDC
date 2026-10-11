# Development environment

English | [日本語（原本）](README.ja.md)

> Translated from the Japanese original. Where the two disagree, the Japanese one is authoritative.

AVideo, MariaDB and Keycloak run in Docker, with the plugin mounted into AVideo.

The `docker-compose.yml` of the AVideo repository is used as it is, with `compose.dev.yml` from this directory layered on top. AVideo's definition is not copied here, so that it cannot be forgotten when AVideo is updated.

## Services

| Service | What it is |
|---|---|
| `avideo` | AVideo (built from upstream's Dockerfile). The Encoder lives in the same container |
| `database` / `database_encoder` | MariaDB, pinned to an LTS release (change it with `MARIADB_VERSION`) |
| `memcached` | As upstream defines it |
| `keycloak` | Starts with the test realm imported |

Upstream's `live` service (the streaming server) is not started.

## Requirements

- Docker and Docker Compose (v2.24 or later, for `build: !reset null`)
- A host name the browser can reach. It is written `<HOST>` below

## Preparation

1. Get AVideo at the version to work with.

   ```bash
   git clone --depth 1 --branch <version> https://github.com/WWBN/AVideo.git avideo
   ```

2. Create `avideo/.env`. Start from upstream's `env.example` and set these values.

   ```bash
   SERVER_NAME=<HOST>
   CREATE_TLS_CERTIFICATE=yes
   RUN_CERTBOT=no
   ENABLE_PHPMYADMIN=no
   SYSTEM_ADMIN_PASSWORD=<admin password for AVideo>
   CPUS_LIMIT=2
   MEMORY_LIMIT=2G

   # used by compose.dev.yml
   LOGINOIDC_REPO=<absolute path of this repository>
   KEYCLOAK_ADMIN_PASSWORD=<admin password for Keycloak>
   KEYCLOAK_URL=http://<HOST>:8081
   AVIDEO_BASE_URL=https://<HOST>
   ```

   `KEYCLOAK_URL` becomes the issuer Keycloak puts in its tokens. It must equal the address the browser uses.

## Starting

Run this in the AVideo directory.

```bash
cd avideo
docker compose -f docker-compose.yml -f <this repository>/dev/compose.dev.yml \
  up -d avideo database database_encoder memcached keycloak
```

The first start builds the image and installs AVideo. It is done when `avideo` is `healthy`.

| What | URL |
|---|---|
| AVideo | `https://<HOST>/` (self-signed certificate) |
| Keycloak | `http://<HOST>:8081/` |
| Discovery | `http://<HOST>:8081/realms/loginoidc-test/.well-known/openid-configuration` |

## After installing

Run this once after AVideo is installed or reinstalled.

```bash
KEYCLOAK_URL=http://<HOST>:8081 SUDO=sudo bash dev/prepare.sh   # leave SUDO out if docker needs no sudo
```

It does two things.

- Turns off TLS verification for requests from the Encoder to AVideo (AVideo's certificate is self-signed; see "Pitfalls")
- Enables LoginOIDC, creates its tables and points it at the test realm (`dev/setup-plugin.php`)

## Tests

PHPUnit is a development dependency (`vendor/` is not in the repository, and the plugin itself has no external dependency).

```bash
# install the dependencies (the first time, and whenever composer.json changes)
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app composer:2 install

# run with the same PHP as AVideo
docker exec avideo-avideo-1 bash -c "cd /opt/loginoidc && php vendor/bin/phpunit"
```

Do not use AVideo's own `vendor/bin/phpunit`. The PHPUnit shipped with AVideo is missing files and does not run.

## End-to-end tests

`dev/e2e/` holds scenarios that follow the steps a browser takes, with `curl`, and check the results. The parts that connect to AVideo (hooks, sessions) cannot be covered by unit tests, so run these after changing them.

```bash
export AVIDEO_URL=https://<HOST> KEYCLOAK_URL=http://<HOST>:8081
export AVIDEO_ENV_FILE=<path of AVideo's .env> SUDO=sudo   # leave SUDO out if .env and docker need no sudo
bash dev/e2e/run-all.sh
```

| Scenario | What it covers |
|---|---|
| `01-login.sh` | User creation, permissions, refusing a replayed callback, the return address, the requirement to sign in, linking by hand |
| `02-logout.sh` | RP-Initiated Logout, Back-Channel Logout |
| `03-native.sh` | Native sign-in control, the Encoder exception, restoration from the cookie |
| `04-auto-redirect.sh` | Automatic redirect for anonymous visitors |
| `05-upload.sh` | A user signed in through the provider posts a video through the Encoder (it really encodes, so it takes a while) |
| `06-admin.sh` | The settings check page, the status in the plugin list, languages, the encrypted secret |
| `07-sync.sh` | Keycloak sync |

**`07-sync.sh` changes and deletes users at Keycloak**, which is why it runs last. Afterwards, recreate the Keycloak container to put them back (see "Starting over"). Do the same before running the scenarios a second time.

**The Encoder starts working on its queue when its status is requested.** Normally an open Encoder page asks periodically. Uploading without that page open leaves the video unencoded (`05-upload.sh` asks by itself while it waits).

## Trying the sync

```bash
docker exec -u www-data -e LOGINOIDC_SYNC_CLIENT_SECRET=dev-only-sync-secret avideo-avideo-1 \
  php /var/www/html/AVideo/plugin/LoginOIDC/cli/sync.php --dry-run
```

Keep `-u www-data`. Run as root, it leaves cache files owned by root that the web side cannot read.

## The test realm

`keycloak/realm-loginoidc-test.json`. The realm is called `loginoidc-test`.

| Client | Purpose | Secret |
|---|---|---|
| `avideo` | Sign-in | `dev-only-avideo-secret` |
| `avideo-sync` | Sync (a service account with only `view-users` and `query-users`) | `dev-only-sync-secret` |

| User | Password | Groups | Client roles (inherited from the groups) |
|---|---|---|---|
| `alice` | `alice` | `avideo-admins` | `admin` |
| `bob` | `bob` | `avideo-uploaders`, `avideo-viewers` | `uploader`, `viewer` |
| `carol` | `carol` | `avideo-viewers` | `viewer` |
| `dave` | `dave` | none (and an unverified e-mail address) | none |

Permissions are handed out through groups, and the groups carry client roles. Both appear in the ID Token and in UserInfo.

- `groups`: the group names (for example `["avideo-uploaders", "avideo-viewers"]`)
- `resource_access.avideo.roles`: the client roles

`setup-plugin.php` configures the plugin to decide permissions from `groups`. The sync client can read groups with `view-users` alone, so sign-in and sync work from the same values (see "Reading the values permissions derive from" in [docs/spec.md](../docs/spec.md)).

Back-channel logout notices go to `http://<HOST>/plugin/LoginOIDC/backchannelLogout.php` (http). AVideo's certificate is self-signed, and Keycloak fails to verify it over https. Set `AVIDEO_BACKCHANNEL_BASE_URL` to change it.

**The secrets and passwords are fixed values for development only.** Never run this realm where it can be reached from outside.

## Starting over

**Keycloak**: its data is not persisted, so recreating the container imports the realm again. The user IDs (= `sub`) are fixed in the realm definition and stay the same.

```bash
docker compose -f ... -f ... up -d --force-recreate keycloak
```

**AVideo and the databases**: remove the volumes and AVideo's installation state.

```bash
docker compose -f ... -f ... down
docker volume rm avideo_avideo_db avideo_avideo_db_encoder
sudo rm -rf .compose/videos .compose/encoder/videos/configuration.php
docker compose -f ... -f ... up -d avideo database database_encoder memcached keycloak
```

Then run `dev/prepare.sh`.

**Remove the Encoder's configuration file as well.** If `.compose/encoder/videos/configuration.php` survives while the database is gone, the installer takes the Encoder for configured and creates no tables in the empty database. Opening the Encoder then fails with `Table 'avideo_encoder.encoder_queue' doesn't exist`. If that happened, remove the file and run the installer again.

```bash
docker exec avideo-avideo-1 bash -c "rm -f /var/www/html/AVideo/Encoder/videos/configuration.php \
  && cd /var/www/html/AVideo/install && php ./cli.php \
  && chown -R www-data:www-data /var/www/html/AVideo/Encoder/videos"
```

## Pitfalls

**The entrypoint of `avideo` changes the owner of the whole AVideo tree to `www-data` on start.** The AVideo directory is mounted as it is, so the files on the host change too.

- Upstream keeps the database files in `./.compose/db`, so MariaDB can no longer read its own data and the AVideo installation fails with error 1018. `compose.dev.yml` moves them to named volumes
- `avideo/.env` ends up owned by `www-data` too. Reading or editing it after the first start needs `sudo`

**Upstream uses `mariadb:latest`.** That can be a short-term release, and the version could change with every rebuild. `compose.dev.yml` pins an LTS release.

**Inside the `avideo` container, its own host name (`SERVER_NAME`) points at 127.0.0.1**, because the entrypoint adds it to `/etc/hosts`. A Keycloak published under the same host name from another container is reachable from the browser but not from the `avideo` container (discovery fails). `compose.dev.yml` solves it by putting Keycloak on the network of `avideo` (`network_mode: "service:avideo"`). As a result Keycloak's port is published by the `avideo` service, and `keycloak` has to be recreated whenever `avideo` is.

**The Encoder page shows the warnings `Undefined variable $aURL` and `$json_file`.** AVideo's certificate is self-signed, so the Encoder fails TLS verification when it fetches AVideo's settings (the wording of the warnings comes from a separate upstream bug in the code that logs the failure). In the development environment, verification is turned off in the Encoder's configuration file. `dev/prepare.sh` does that. By hand it is:

```bash
docker exec avideo-avideo-1 bash -c 'cd /var/www/html/AVideo/Encoder/videos && grep -q disableSSLVerification configuration.php \
  || sed -i "s|^require_once .*include_config.php.;|\$global[\"disableSSLVerification\"] = true;\n&|" configuration.php'
```

Never turn it off in production. Use a proper certificate.

**AVideo's log goes to the container's standard error, not to `videos/avideo.log`.** Read it with `docker logs avideo-avideo-1`. Anything that is not ASCII is escaped.

**Keycloak does not start with a named volume for its data.** The volume belongs to root and Keycloak (uid 1000) cannot write to it. That is why its data is not persisted.
