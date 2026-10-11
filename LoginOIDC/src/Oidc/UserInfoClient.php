<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

use LoginOIDC\Http\HttpClient;
use LoginOIDC\Http\HttpException;

/** Fetches claims from the UserInfo endpoint. */
final class UserInfoClient
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * @param  string $expectedSubject sub of the ID Token
     * @return array<string, mixed>
     * @throws OidcException when it cannot be fetched or its sub differs from the ID Token
     */
    public function fetch(
        ProviderMetadata $metadata,
        #[\SensitiveParameter] string $accessToken,
        string $expectedSubject,
    ): array {
        if ($metadata->userinfoEndpoint === '' || $accessToken === '') {
            throw new OidcException('UserInfo is enabled, but the provider returned no userinfo_endpoint or no access token');
        }

        try {
            $response = $this->http->request(
                $metadata->userinfoEndpoint,
                ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $accessToken],
            );
            if (!$response->isSuccess()) {
                throw new OidcException("cannot fetch UserInfo (HTTP {$response->status})");
            }
            $claims = $response->json();
        } catch (HttpException $e) {
            throw new OidcException("cannot fetch UserInfo: {$e->getMessage()}", 0, $e);
        }

        // A response with a different sub is not used. This stops another person's claims being mixed in
        // (OpenID Connect Core 1.0, section 5.3.2).
        if (($claims['sub'] ?? null) !== $expectedSubject) {
            throw new OidcException('UserInfo sub does not match the ID Token');
        }

        return $claims;
    }
}
