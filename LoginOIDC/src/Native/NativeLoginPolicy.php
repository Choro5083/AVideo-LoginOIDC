<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Native;

use LoginOIDC\NativeLoginMode;
use LoginOIDC\Settings;

final class NativeLoginPolicy
{
    /**
     * ログインを拒否する理由を返す。許すなら null。
     */
    public static function reasonToDeny(Settings $settings, NativeLoginAttempt $attempt): ?string
    {
        // Encoder は、投稿者本人のユーザ名とパスワードのハッシュで AVideo にログインする。
        // これを止めるとアップロードが壊れるので、設定したアドレスからのログインは制御しない。
        if (IpMatcher::matchesAny($attempt->remoteAddress, $settings->encoderAddresses)) {
            return null;
        }

        // IdP に紐付いたユーザは、Cookie からログイン状態を復元させない。復元を許すと、
        // IdP 側でログアウトや無効化をしても、Cookie を持つ端末でログインが続いてしまう。
        if ($attempt->linked && $attempt->viaCookie) {
            return 'restoring a sign-in from the cookie is not allowed for users linked to the provider';
        }

        return match ($settings->nativeLoginMode) {
            NativeLoginMode::Allow => null,
            NativeLoginMode::DenyLinked => $attempt->linked
                ? 'native sign-in is not allowed for users linked to the provider'
                : null,
            NativeLoginMode::Allowlist => in_array($attempt->username, $settings->nativeLoginAllowlist, true)
                ? null
                : 'the user is not in the native sign-in allowlist',
        };
    }
}
