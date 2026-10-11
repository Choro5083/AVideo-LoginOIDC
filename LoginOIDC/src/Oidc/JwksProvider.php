<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

use LoginOIDC\Http\HttpClient;
use LoginOIDC\Http\HttpException;

/** Fetches and caches the provider's public keys (JWKS). */
final class JwksProvider
{
    private const CACHE_TTL_SECONDS = 3600;
    private const MIN_REFRESH_INTERVAL_SECONDS = 30;

    public function __construct(
        private readonly HttpClient $http,
        private readonly Cache $cache,
    ) {
    }

    /**
     * Returns the JWKS of the keys used to verify signatures.
     *
     * @param  bool $refresh fetch again instead of using the cache (to follow a key rotation)
     * @return array{keys: list<array<string, mixed>>}
     * @throws OidcException when it cannot be fetched or is malformed
     */
    public function signingKeys(ProviderMetadata $metadata, bool $refresh = false): array
    {
        $cacheKey = 'jwks:' . $metadata->jwksUri;
        // Refetching is spaced out. Otherwise sending tokens with unknown kids would be enough to make
        // this server keep hitting the provider.
        $refreshKey = 'jwks-refreshed:' . $metadata->jwksUri;
        if ($refresh && $this->cache->get($refreshKey) !== null) {
            $refresh = false;
        }
        $refreshing = $refresh;
        $jwks = $refresh ? null : $this->cache->get($cacheKey);

        if ($jwks === null) {
            try {
                $response = $this->http->request($metadata->jwksUri, ['Accept' => 'application/json']);
                if (!$response->isSuccess()) {
                    throw new OidcException("cannot fetch JWKS (HTTP {$response->status}): {$metadata->jwksUri}");
                }
                $jwks = $response->json();
            } catch (HttpException $e) {
                throw new OidcException("cannot fetch JWKS: {$e->getMessage()}", 0, $e);
            }
            $this->cache->set($cacheKey, $jwks, self::CACHE_TTL_SECONDS);
            if ($refreshing) {
                $this->cache->set($refreshKey, ['at' => time()], self::MIN_REFRESH_INTERVAL_SECONDS);
            }
        }

        $keys = $jwks['keys'] ?? null;
        if (!is_array($keys)) {
            throw new OidcException('JWKS has no "keys" member');
        }

        // Keys for encryption (use = enc) are not used to verify signatures.
        return ['keys' => array_values(array_filter(
            $keys,
            static fn (mixed $key): bool => is_array($key) && ($key['use'] ?? 'sig') === 'sig',
        ))];
    }
}
