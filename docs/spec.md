# 仕様

## 機能の層

機能を4つの層に分けて決める。このリポジトリが扱うのは (1)〜(3)。

| 層 | 内容 | 状態 |
|---|---|---|
| (1) OIDC の RP としての機能 | プロトコルとしての振る舞い | 確定 |
| (2) AVideo との統合 | ユーザの作成と突き合わせ、claim から権限への対応、ネイティブログインの制御 | 未決 |
| (3) IdP 固有の拡張 | Keycloak Admin API による定期同期（任意機能） | 未決 |
| (4) 利用者側の設定値 | role 名、IdP の URL など | このリポジトリでは扱わない |

**特定の環境の値（ホスト名、realm 名など）はリポジトリに入れない。** すべて設定で受け取る。

## (1) OIDC の RP としての機能

### 基本

| 機能 | 対応 | 備考 |
|---|---|---|
| Discovery（`.well-known/openid-configuration`） | 対応 | 設定は issuer の URL だけで済むようにする |
| Authorization Code Flow | 対応 | 唯一の対象フロー |
| PKCE（S256） | 対応 | |
| `state` / `nonce` の検証 | 対応 | |
| ID Token の検証 | 対応 | 署名、`iss` / `aud` / `exp` / `iat` / `nonce` |
| JWKS の取得とキャッシュ | 対応 | `kid` が見つからなければ取り直す（鍵のローテーションに追従するため） |
| UserInfo エンドポイント | 対応（任意） | ID Token に必要な claim がない IdP 向け |
| scope の指定 | 対応 | 既定は `openid profile email` |
| Implicit / Hybrid Flow | 対象外 | 非推奨のため |
| Refresh Token | 対象外 | ログイン後は AVideo のセッションで動くため、トークンを持ち続ける必要がない |

### クライアント認証

トークンエンドポイントで、プラグイン自身が IdP に身元を示す方式。

| 方式 | 対応 |
|---|---|
| `client_secret_basic` | v1 |
| `client_secret_post` | v1 |
| `private_key_jwt` | v2 |

**方式は差し替えられる作りにする。** v2 で `private_key_jwt` を足すときに、他の部分に手を入れずに済むようにするため。

IdP が対応している方式は Discovery の `token_endpoint_auth_methods_supported` で分かる。対応していない方式は選べないようにする。

### ログアウトとセッション

| 機能 | 対応 | 備考 |
|---|---|---|
| RP-Initiated Logout | 対応 | AVideo でログアウトすると IdP 側もログアウトする |
| Back-Channel Logout | 対応 | IdP からの通知で AVideo のセッションを切る |
| Front-Channel Logout | 対象外 | ブラウザの第三者 Cookie 制限で動かないことが多い |
| Session Management（iframe） | 対象外 | 同上 |

### IdP の数

**扱う IdP は1つ。** 複数の IdP をまとめるのは IdP 側の役割（Keycloak の Identity Brokering など）と考える。SNS ログインは AVideo に既存のプラグインがある。

**ユーザの識別子は issuer と `sub` の組で保存する。** `sub` は IdP ごとに一意なだけなので、issuer の設定を誤って別の IdP に向けたときに、別人のアカウントに入れてしまう事故を防ぐ。

### 運用

| 機能 | 備考 |
|---|---|
| 時刻のずれの許容（clock skew） | 設定できるようにする |
| TLS 証明書の検証 | 無効にする設定は用意しない。独自の CA を使う場合のために、CA 証明書のパスだけ指定できるようにする |
| デバッグログ | トークンの中身はログに出さない |

### 実装方針

**RP の処理は自作し、JWT の署名検証と JWKS の処理だけ AVideo 本体同梱の `firebase/php-jwt` を使う。**

- OIDC クライアントライブラリを同梱すると、AVideo 本体の `vendor/` と同じ PHP プロセスで読み込まれ、依存の版が衝突するおそれがある
- AVideo 同梱の Hybridauth の Keycloak プロバイダは、Discovery・ID Token の検証・nonce・PKCE を持たないため使わない
- 署名の検証は自作しない

本体の `firebase/php-jwt` の版に依存するので、AVideo の更新時に動作を確認する（README の「動作確認済みの AVideo」）。

## (2) AVideo との統合

未決。決める予定の項目。

- ユーザの作成（初回ログイン時の JIT）と、ログインのたびの属性の更新
- claim から AVideo のフラグ（`isAdmin` / `canUpload` など）への対応
- claim から user group（動画の公開範囲）への対応（任意機能）
- ログインに必要な claim の指定
- 未ログイン時に IdP へ自動でリダイレクトするか
- ネイティブログインの制御（指定したユーザだけ許可する）
- Encoder からのログイン（Encoder は AVideo のユーザ名とパスワードでログインする）
- モバイルアプリ（MobileManager）からのログイン

## (3) IdP 固有の拡張

未決。決める予定の項目。

- Keycloak Admin API による定期同期（削除・無効化・権限の剥奪の反映）。cron から実行する CLI
