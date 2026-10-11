<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC;

/** ネイティブログイン（ユーザ名とパスワード）の制御モード。 */
enum NativeLoginMode: string
{
    /** 制限しない */
    case Allow = 'allow';
    /** IdP に紐付いたユーザだけ禁止する */
    case DenyLinked = 'denyLinked';
    /** 許可リストのユーザだけ許可する */
    case Allowlist = 'allowlist';
}
