<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

/** client_secret_basic. Sends the ID and the secret in the Authorization header. */
final class SecretBasicAuthenticator implements ClientAuthenticator
{
    public function __construct(
        private readonly string $clientId,
        #[\SensitiveParameter] private readonly string $clientSecret,
    ) {
    }

    public function method(): string
    {
        return 'client_secret_basic';
    }

    public function apply(array $headers, array $form): array
    {
        // The ID and the secret are form-encoded before Base64
        // (RFC 6749, section 2.3.1). It cannot be skipped: secrets with symbols would come out different.
        $credentials = urlencode($this->clientId) . ':' . urlencode($this->clientSecret);

        return [$headers + ['Authorization' => 'Basic ' . base64_encode($credentials)], $form];
    }
}
