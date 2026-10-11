<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

use LoginOIDC\Http\HttpClient;
use LoginOIDC\Http\HttpException;

/** UserInfo エンドポイントから claim を取る。 */
final class UserInfoClient
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * @param  string $expectedSubject ID Token の sub
     * @return array<string, mixed>
     * @throws OidcException 取得できない、または sub が ID Token と違うとき
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

        // sub が一致しない応答は使わない。別人の情報を混ぜる攻撃を防ぐ
        // （OpenID Connect Core 1.0 の 5.3.2 節）。
        if (($claims['sub'] ?? null) !== $expectedSubject) {
            throw new OidcException('UserInfo sub does not match the ID Token');
        }

        return $claims;
    }
}
