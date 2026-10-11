<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

use LoginOIDC\Http\HttpClient;
use LoginOIDC\Http\HttpException;

/** Exchanges the authorization code for tokens. */
final class TokenClient
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly ClientAuthenticator $authenticator,
    ) {
    }

    /** @throws OidcException when the exchange fails */
    public function exchangeCode(
        ProviderMetadata $metadata,
        string $code,
        string $redirectUri,
        #[\SensitiveParameter] string $codeVerifier,
    ): TokenResponse {
        if (!in_array($this->authenticator->method(), $metadata->tokenEndpointAuthMethods, true)) {
            $supported = implode(', ', $metadata->tokenEndpointAuthMethods);
            throw new OidcException(
                "the provider does not support client authentication method {$this->authenticator->method()} (supported: {$supported})"
            );
        }

        [$headers, $form] = $this->authenticator->apply(
            ['Accept' => 'application/json'],
            [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'code_verifier' => $codeVerifier,
            ],
        );

        try {
            $response = $this->http->request($metadata->tokenEndpoint, $headers, $form);
            $body = $response->json();
        } catch (HttpException $e) {
            throw new OidcException("no usable response from the token endpoint: {$e->getMessage()}", 0, $e);
        }

        if (!$response->isSuccess()) {
            // error and error_description are the provider's explanation and contain no secrets (RFC 6749, section 5.2).
            $error = is_string($body['error'] ?? null) ? $body['error'] : 'unknown';
            $description = is_string($body['error_description'] ?? null) ? " ({$body['error_description']})" : '';
            throw new OidcException("token exchange was rejected (HTTP {$response->status}): {$error}{$description}");
        }

        $idToken = $body['id_token'] ?? null;
        if (!is_string($idToken) || $idToken === '') {
            throw new OidcException('token response has no id_token. Check that the scope includes openid');
        }
        $accessToken = $body['access_token'] ?? '';

        return new TokenResponse($idToken, is_string($accessToken) ? $accessToken : '');
    }
}
