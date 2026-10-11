<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

final class TokenResponse
{
    public function __construct(
        #[\SensitiveParameter] public readonly string $idToken,
        #[\SensitiveParameter] public readonly string $accessToken,
    ) {
    }
}
