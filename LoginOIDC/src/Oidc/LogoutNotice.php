<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

/** 検証済みの Logout Token が指すもの。sid と sub の少なくとも片方がある。 */
final class LogoutNotice
{
    public function __construct(
        public readonly string $issuer,
        /** 無ければ空文字 */
        public readonly string $subject,
        /** IdP のセッションの ID。無ければ空文字 */
        public readonly string $sessionId,
    ) {
    }
}
