# AVideo-LoginOIDC

[AVideo](https://github.com/WWBN/AVideo) に OpenID Connect によるログインを追加するプラグイン（プラグイン名 `LoginOIDC`）。

Keycloak をはじめ、OpenID Connect に対応した任意の IdP で使えることを目指す。

> **AVideo 公式のプラグインではない。** WWBN とは関係のない個人の制作物。

## 状態

開発中。仕様（`docs/spec.md`）の v1 の範囲は実装済みで、開発環境（`dev/README.md`）で動作を確認している。実運用での検証はこれから。

## 動作確認済みの AVideo

| AVideo | コミット | 確認日 |
|---|---|---|
| v29.3.0 | `12d5c1042` | 2026-10-11（開発環境。MariaDB 12.3、PHP 8.5、Keycloak 26.8.0、Encoder は同日の最新） |

## 依存

外部ライブラリは同梱しない。JWT の検証には AVideo 本体に同梱されている `firebase/php-jwt` を使う。

## ライセンス

MIT License。詳細は `LICENSE` を参照。

AVideo は WWBN の商標。
