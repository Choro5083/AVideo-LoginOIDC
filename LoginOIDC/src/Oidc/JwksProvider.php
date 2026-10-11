<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

use LoginOIDC\Http\HttpClient;
use LoginOIDC\Http\HttpException;

/** IdP の公開鍵（JWKS）を取り、キャッシュする。 */
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
     * 署名の検証に使う鍵の JWKS を返す。
     *
     * @param  bool $refresh キャッシュを使わずに取り直す（鍵のローテーションに追従するとき）
     * @return array{keys: list<array<string, mixed>>}
     * @throws OidcException 取得できない、または内容が正しくないとき
     */
    public function signingKeys(ProviderMetadata $metadata, bool $refresh = false): array
    {
        $cacheKey = 'jwks:' . $metadata->jwksUri;
        // 取り直しは間隔を空ける。知らない kid のトークンを送り続けるだけで、IdP へ
        // リクエストを打たせ続けられないようにする。
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

        // 暗号化用の鍵（use = enc）は署名の検証に使わない。
        return ['keys' => array_values(array_filter(
            $keys,
            static fn (mixed $key): bool => is_array($key) && ($key['use'] ?? 'sig') === 'sig',
        ))];
    }
}
