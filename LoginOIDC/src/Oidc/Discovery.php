<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

use LoginOIDC\Http\HttpClient;
use LoginOIDC\Http\HttpException;

/** issuer から Discovery 文書（.well-known/openid-configuration）を取る。 */
final class Discovery
{
    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly HttpClient $http,
        private readonly Cache $cache,
        private readonly bool $allowInsecureHttp = false,
    ) {
    }

    /** @throws OidcException 取得できない、または内容が正しくないとき */
    public function fetch(string $issuer): ProviderMetadata
    {
        $this->assertAllowedScheme($issuer, 'issuer');

        $cacheKey = 'discovery:' . $issuer;
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return ProviderMetadata::fromArray($cached);
        }

        $url = $issuer . '/.well-known/openid-configuration';
        try {
            $response = $this->http->request($url, ['Accept' => 'application/json']);
            if (!$response->isSuccess()) {
                throw new OidcException("cannot fetch the discovery document (HTTP {$response->status}): {$url}");
            }
            $metadata = ProviderMetadata::fromArray($response->json());
        } catch (HttpException $e) {
            throw new OidcException("cannot fetch the discovery document: {$e->getMessage()}", 0, $e);
        }

        // issuer が一致しない文書を受け入れると、別の IdP のエンドポイントへ
        // 誘導されうる（OpenID Connect Discovery 1.0 の 4.3 節）。
        if ($metadata->issuer !== $issuer) {
            throw new OidcException(
                "discovery document issuer does not match the configured issuer (configured: {$issuer}, document: {$metadata->issuer})"
            );
        }
        foreach (['authorization_endpoint' => $metadata->authorizationEndpoint,
                  'token_endpoint' => $metadata->tokenEndpoint,
                  'jwks_uri' => $metadata->jwksUri] as $name => $endpoint) {
            $this->assertAllowedScheme($endpoint, $name);
        }

        $this->cache->set($cacheKey, $metadata->toArray(), self::CACHE_TTL_SECONDS);

        return $metadata;
    }

    private function assertAllowedScheme(string $url, string $name): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme === 'https' || ($scheme === 'http' && $this->allowInsecureHttp)) {
            return;
        }
        throw new OidcException("{$name} must use https: {$url}");
    }
}
