# 開発環境

AVideo 本体・MariaDB・Keycloak を Docker で動かし、プラグインをマウントして試す。

AVideo 本体のリポジトリにある `docker-compose.yml` をそのまま使い、このディレクトリの `compose.dev.yml` を重ねる。本体の定義を写して持たないのは、本体の版を上げたときに追従し忘れないようにするため。

## 構成

| サービス | 内容 |
|---|---|
| `avideo` | AVideo 本体（本家の Dockerfile でビルド）。Encoder も同じコンテナに入る |
| `database` / `database_encoder` | MariaDB（LTS に固定。`MARIADB_VERSION` で変えられる） |
| `memcached` | 本家の定義のまま |
| `keycloak` | テスト用の realm を取り込んで起動する |

本家の定義にある `live`（配信サーバ）は起動しない。

## 前提

- Docker と Docker Compose（`build: !reset null` を使うので v2.24 以降）
- ブラウザから見えるホスト名。以下では `<HOST>` と書く

## 準備

1. AVideo 本体を、対象の版で取得する。

   ```bash
   git clone --depth 1 --branch <版> https://github.com/WWBN/AVideo.git avideo
   ```

2. `avideo/.env` を作る。本家の `env.example` を元に、次の値を設定する。

   ```bash
   SERVER_NAME=<HOST>
   CREATE_TLS_CERTIFICATE=yes
   RUN_CERTBOT=no
   ENABLE_PHPMYADMIN=no
   SYSTEM_ADMIN_PASSWORD=<AVideo の管理者パスワード>
   CPUS_LIMIT=2
   MEMORY_LIMIT=2G

   # compose.dev.yml が使う値
   LOGINOIDC_REPO=<このリポジトリの絶対パス>
   KEYCLOAK_ADMIN_PASSWORD=<Keycloak の管理者パスワード>
   KEYCLOAK_URL=http://<HOST>:8081
   AVIDEO_BASE_URL=https://<HOST>
   ```

   `KEYCLOAK_URL` は Keycloak が発行する issuer に使われる。ブラウザからのアクセス先と一致させる。

## 起動

AVideo 本体のディレクトリで実行する。

```bash
cd avideo
docker compose -f docker-compose.yml -f <このリポジトリ>/dev/compose.dev.yml \
  up -d avideo database database_encoder memcached keycloak
```

初回はイメージのビルドと AVideo のインストールが走る。`avideo` が `healthy` になれば完了。

| 対象 | URL |
|---|---|
| AVideo | `https://<HOST>/`（証明書は自己署名） |
| Keycloak | `http://<HOST>:8081/` |
| Discovery | `http://<HOST>:8081/realms/loginoidc-test/.well-known/openid-configuration` |

## プラグインを有効にする

`avideo` のコンテナの中で実行する。プラグインを有効にし、テーブルを作り、テスト用の realm に向けた設定を入れる。

```bash
docker exec avideo-avideo-1 php /opt/loginoidc/dev/setup-plugin.php http://<HOST>:8081
```

## テスト

PHPUnit は開発用の依存として入れる（`vendor/` はリポジトリに含めない。プラグイン本体には外部の依存はない）。

```bash
# 依存を入れる（初回と、composer.json を変えたとき）
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app composer:2 install

# AVideo 本体と同じ PHP で流す
docker exec avideo-avideo-1 bash -c "cd /opt/loginoidc && php vendor/bin/phpunit"
```

AVideo 本体の `vendor/bin/phpunit` は使わない。本体に同梱されている PHPUnit は一部のファイルが欠けていて動かない。

## 同期を試す

```bash
docker exec -u www-data -e LOGINOIDC_SYNC_CLIENT_SECRET=dev-only-sync-secret avideo-avideo-1 \
  php /var/www/html/AVideo/plugin/LoginOIDC/cli/sync.php --dry-run
```

`-u www-data` を付ける。root で実行すると、キャッシュのファイルが root の持ち物になり、Web 側から読めなくなる。

## テスト用の realm

`keycloak/realm-loginoidc-test.json`。realm 名は `loginoidc-test`。

| client | 用途 | シークレット |
|---|---|---|
| `avideo` | ログイン | `dev-only-avideo-secret` |
| `avideo-sync` | 同期（service account。`view-users` / `query-users` だけを持つ） | `dev-only-sync-secret` |

| ユーザ | パスワード | group | client role（group から継承） |
|---|---|---|---|
| `alice` | `alice` | `avideo-admins` | `admin` |
| `bob` | `bob` | `avideo-uploaders`、`avideo-viewers` | `uploader`、`viewer` |
| `carol` | `carol` | `avideo-viewers` | `viewer` |
| `dave` | `dave` | なし（メールアドレスも未確認） | なし |

権限は group で配り、group に client role を割り当ててある。ID Token と UserInfo には、両方が入る。

- `groups`: group の名前（例: `["avideo-uploaders", "avideo-viewers"]`）
- `resource_access.avideo.roles`: client role

`setup-plugin.php` は `groups` の方で権限を決める設定を入れる。同期用の client が `view-users` だけで group を読めるので、ログイン時と同期で同じ値を使える（`docs/spec.md` の「権限の元になる値の読み方」）。

Back-Channel Logout の通知先は `http://<HOST>/plugin/LoginOIDC/backchannelLogout.php`（http）。AVideo の証明書が自己署名で、https にすると Keycloak が検証に失敗するため。変えるなら `AVIDEO_BACKCHANNEL_BASE_URL` を設定する。

**シークレットとパスワードは開発専用の固定値。** この realm を外部から届く場所で動かさない。

## 作り直す

**Keycloak**: データを永続化していないので、コンテナを作り直せば realm 定義から入り直す。ユーザの ID（= `sub`）は realm 定義で固定してあり、作り直しても変わらない。

```bash
docker compose -f ... -f ... up -d --force-recreate keycloak
```

**AVideo と DB**: ボリュームと、AVideo のインストール状態を消す。

```bash
docker compose -f ... -f ... down
docker volume rm avideo_avideo_db avideo_avideo_db_encoder
sudo rm -rf .compose/videos
docker compose -f ... -f ... up -d avideo database database_encoder memcached keycloak
```

## はまった点

**`avideo` の entrypoint は、起動時に AVideo のツリー全体の持ち主を `www-data` に変える。** 本体のディレクトリをそのままマウントしているため、ホスト側のファイルも変わる。

- 本家の定義は DB のデータを `./.compose/db` に置くので、MariaDB が自分のデータを読めなくなり、AVideo のインストールがエラー 1018 で失敗する。`compose.dev.yml` で名前付きボリュームに逃がしてある
- `avideo/.env` も `www-data` の持ち物になる。起動後に読み書きするときは `sudo` が要る

**本家の定義は `mariadb:latest` を使う。** 短期サポートの版になることがあり、作り直すたびに版が変わりうる。`compose.dev.yml` で LTS に固定してある。

**`avideo` のコンテナの中では、自分のホスト名（`SERVER_NAME`）が 127.0.0.1 を指す。** entrypoint が `/etc/hosts` に書き足すため。Keycloak を別のコンテナとして同じホスト名で公開すると、ブラウザからは届くが、`avideo` のコンテナからは届かない（Discovery が失敗する）。`compose.dev.yml` では、Keycloak を `avideo` と同じネットワークに置いて（`network_mode: "service:avideo"`）解決してある。そのため Keycloak のポートは `avideo` のサービスで公開していて、`avideo` を作り直したら `keycloak` も作り直す必要がある。

**AVideo のログは `videos/avideo.log` ではなく、コンテナの標準エラーに出る。** `docker logs avideo-avideo-1` で読む。ASCII 以外はエスケープされる。

**Keycloak のデータ用に名前付きボリュームを付けると起動しない。** ボリュームが root の持ち物になり、Keycloak（uid 1000）が書けないため。永続化しない構成にしてある。
