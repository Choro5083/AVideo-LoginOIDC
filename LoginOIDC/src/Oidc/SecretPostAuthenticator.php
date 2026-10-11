<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

/** client_secret_post。ID とシークレットをリクエストの本文で送る。 */
final class SecretPostAuthenticator implements ClientAuthenticator
{
    public function __construct(
        private readonly string $clientId,
        #[\SensitiveParameter] private readonly string $clientSecret,
    ) {
    }

    public function method(): string
    {
        return 'client_secret_post';
    }

    public function apply(array $headers, array $form): array
    {
        return [$headers, $form + ['client_id' => $this->clientId, 'client_secret' => $this->clientSecret]];
    }
}
