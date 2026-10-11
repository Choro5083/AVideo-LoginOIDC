# AVideo-LoginOIDC

[English](README.md) | 日本語（原本）

[AVideo](https://github.com/WWBN/AVideo) に OpenID Connect によるログインを追加するプラグイン（プラグイン名 `LoginOIDC`）。

Keycloak をはじめ、OpenID Connect に対応した任意の IdP で使えることを目指す。

> **AVideo 公式のプラグインではない。** WWBN とは関係のない個人の制作物。

## できること

- Authorization Code Flow + PKCE によるログイン。Discovery に対応し、設定は issuer の URL から始められる
- IdP のユーザと AVideo のユーザを `sub` で突き合わせる。初回ログイン時にユーザを作る
- claim の値から、AVideo の権限（管理者、投稿など）と user group を決める
- AVideo でのログアウトを IdP に伝える（RP-Initiated Logout）。IdP でのログアウトを AVideo に反映する（Back-Channel Logout）
- ユーザ名とパスワードによるログインを制限する（IdP が止まったときのための例外を残せる）
- Keycloak 側の削除・無効化・権限の変更を、定期的に AVideo に反映する（任意）
- 未ログインの利用者を、自動で IdP のログインへ送る（任意）
- 設定の誤りを管理画面に表示する
- 画面の文言を、AVideo の言語設定に合わせて表示する（英語、日本語）

詳しい仕様と、そう決めた理由は [docs/spec.ja.md](docs/spec.ja.md) にある。

## 状態

開発中。仕様の v1 の範囲は実装済みで、開発環境で動作を確認している。実運用での検証はこれから。

## 動作確認済みの AVideo

| AVideo | コミット | 確認日 |
|---|---|---|
| v29.3.0 | `12d5c1042` | 2026-10-11（開発環境。MariaDB 12.3、PHP 8.5、Keycloak 26.8.0、Encoder は同日の最新） |

AVideo 本体を更新したら、動作を確かめること。このプラグインは、本体に同梱されているライブラリと、本体のフックに依存している。

## 導入

1. このリポジトリの `LoginOIDC/` を、AVideo の `plugin/LoginOIDC/` に置く
2. AVideo の管理画面のプラグイン一覧で、LoginOIDC を有効にし、テーブルを作る
3. IdP に client を登録する。リダイレクト URI は、プラグインの一覧の説明欄に表示される
4. プラグインの設定に、issuer、client ID、client secret を入れる
5. プラグインの一覧の「Check settings」で、設定に問題がないことを確かめる

権限の対応、ネイティブログインの制御、Keycloak の同期などの設定は、[docs/spec.ja.md](docs/spec.ja.md) を参照。

## 依存

- PHP 8.1 以上
- 外部ライブラリは同梱しない。JWT の検証には、AVideo 本体に同梱されている `firebase/php-jwt` を使う
- DB は MariaDB と MySQL の両方で動く

## 文書

| 文書 | 内容 |
|---|---|
| [docs/spec.ja.md](docs/spec.ja.md) | 仕様と、設計の判断 |
| [dev/README.ja.md](dev/README.ja.md) | 開発環境の作り方、テストの流し方 |

**文書は日本語が原本。** 変更するときは、日本語（`*.ja.md`）を直してから、英語（`*.md`）に訳す。内容が食い違っているときは、日本語を正とする。

## ライセンス

MIT License。詳細は [LICENSE](LICENSE) を参照。

AVideo は WWBN の商標。
