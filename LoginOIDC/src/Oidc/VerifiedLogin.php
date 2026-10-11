<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

/** A provider authentication that was verified. */
final class VerifiedLogin
{
    /**
     * @param array<string, mixed> $claims claims of the ID Token, merged with UserInfo when that is enabled
     */
    public function __construct(
        public readonly string $issuer,
        public readonly string $subject,
        public readonly array $claims,
        /** ID of the provider session, used for back-channel logout. An empty string when absent */
        public readonly string $sessionId,
        /** passed to the provider as id_token_hint at logout */
        #[\SensitiveParameter] public readonly string $idToken,
        public readonly string $returnTo,
    ) {
    }
}
