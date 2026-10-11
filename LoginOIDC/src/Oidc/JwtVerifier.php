<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * IdP が署名した JWT（ID Token、Logout Token）の署名と有効期限を検証する。
 *
 * 署名の検証と JWKS の解釈は firebase/php-jwt に任せ、自作しない。
 * claim の内容（iss、aud など）は、トークンの種類ごとに呼ぶ側が検証する。
 */
final class JwtVerifier
{
    /**
     * 受け入れる署名アルゴリズム。公開鍵で検証するものに限る。
     * 共通鍵（HS*）を受け入れると、公開鍵を共通鍵として使わせる攻撃の余地が生まれる。
     */
    private const ALLOWED_ALGS = ['RS256', 'RS384', 'RS512', 'ES256', 'ES384', 'EdDSA'];

    public function __construct(
        private readonly JwksProvider $jwks,
        private readonly int $clockSkewSeconds,
    ) {
    }

    /**
     * @param  string $what エラーメッセージに出すトークンの種類
     * @return array<string, mixed> 署名を検証できた claim
     * @throws OidcException 検証に失敗したとき
     */
    public function verify(#[\SensitiveParameter] string $jwt, ProviderMetadata $metadata, int $now, string $what): array
    {
        [$alg, $kid] = self::readHeader($jwt, $what);

        try {
            return $this->decode($jwt, $alg, $kid, $this->jwks->signingKeys($metadata), $now, $what);
        } catch (UnknownKeyException) {
            // IdP が鍵を入れ替えた直後は、キャッシュした JWKS に新しい鍵が無い。1度だけ取り直す。
            try {
                return $this->decode($jwt, $alg, $kid, $this->jwks->signingKeys($metadata, refresh: true), $now, $what);
            } catch (UnknownKeyException) {
                throw new OidcException("the key that signed the {$what} (kid: {$kid}) is not in the JWKS");
            }
        }
    }

    /**
     * ヘッダを読み、受け入れないアルゴリズムを署名の検証より前に弾く。
     *
     * @return array{string, string} [alg, kid]。kid が無ければ空文字
     */
    private static function readHeader(string $jwt, string $what): array
    {
        $segments = explode('.', $jwt);
        if (count($segments) !== 3) {
            throw new OidcException("{$what} is not a JWT");
        }
        $decoded = base64_decode(strtr($segments[0], '-_', '+/'), true);
        $header = $decoded === false ? null : json_decode($decoded, true);
        if (!is_array($header) || !is_string($header['alg'] ?? null)) {
            throw new OidcException("cannot read the {$what} header");
        }
        if (!in_array($header['alg'], self::ALLOWED_ALGS, true)) {
            throw new OidcException("{$what} signing algorithm {$header['alg']} is not accepted");
        }

        return [$header['alg'], is_string($header['kid'] ?? null) ? $header['kid'] : ''];
    }

    /**
     * 署名と有効期限を検証して claim を返す。
     *
     * @param  array{keys: list<array<string, mixed>>} $jwks
     * @return array<string, mixed>
     * @throws UnknownKeyException kid に合う鍵が無いとき
     * @throws OidcException       それ以外の検証の失敗
     */
    private function decode(string $jwt, string $alg, string $kid, array $jwks, int $now, string $what): array
    {
        // leeway と timestamp はライブラリの静的な設定で、AVideo 本体も同じクラスを使う。
        // 検証の間だけ変え、必ず元に戻す。
        $previousLeeway = JWT::$leeway;
        $previousTimestamp = JWT::$timestamp;
        JWT::$leeway = $this->clockSkewSeconds;
        JWT::$timestamp = $now;
        try {
            // JWKS の鍵に alg が無い IdP があるため、トークンの alg（許可済みのもの）を既定にする。
            $keys = $jwks['keys'] === [] ? [] : JWK::parseKeySet($jwks, $alg);
            $key = $kid !== '' ? ($keys[$kid] ?? null) : (count($keys) === 1 ? reset($keys) : null);
            if (!$key instanceof Key) {
                throw new UnknownKeyException("kid: {$kid}");
            }
            $payload = JWT::decode($jwt, $key);
        } catch (\UnexpectedValueException | \InvalidArgumentException | \DomainException $e) {
            throw new OidcException("{$what} cannot be verified: {$e->getMessage()}", 0, $e);
        } finally {
            JWT::$leeway = $previousLeeway;
            JWT::$timestamp = $previousTimestamp;
        }

        $claims = json_decode((string) json_encode($payload), true);
        if (!is_array($claims)) {
            throw new OidcException("cannot read the {$what} claims");
        }

        return $claims;
    }
}
