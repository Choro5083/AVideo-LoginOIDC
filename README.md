# AVideo-LoginOIDC

[AVideo](https://github.com/WWBN/AVideo) に OpenID Connect によるログインを追加するプラグイン（プラグイン名 `LoginOIDC`）。

Keycloak をはじめ、OpenID Connect に対応した任意の IdP で使えることを目指す。

> **AVideo 公式のプラグインではない。** WWBN とは関係のない個人の制作物。

## 状態

設計中。まだ動かない。仕様は `docs/spec.md`。

## 動作確認済みの AVideo

| AVideo | コミット | 確認日 |
|---|---|---|
| （未確認） | | |

## 依存

外部ライブラリは同梱しない。JWT の検証には AVideo 本体に同梱されている `firebase/php-jwt` を使う。

## ライセンス

MIT License。詳細は `LICENSE` を参照。

AVideo は WWBN の商標。
