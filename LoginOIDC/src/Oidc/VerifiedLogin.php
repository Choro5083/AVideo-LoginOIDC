<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

/** IdP での認証が検証できた結果。 */
final class VerifiedLogin
{
    /**
     * @param array<string, mixed> $claims ID Token の claim。UserInfo を読む設定なら、その内容も合わせたもの
     */
    public function __construct(
        public readonly string $issuer,
        public readonly string $subject,
        public readonly array $claims,
        /** IdP のセッションの ID。Back-Channel Logout で使う。無ければ空文字 */
        public readonly string $sessionId,
        /** ログアウト時に id_token_hint として IdP に渡す */
        #[\SensitiveParameter] public readonly string $idToken,
        public readonly string $returnTo,
    ) {
    }
}
