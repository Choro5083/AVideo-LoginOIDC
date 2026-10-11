<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * ID Token を検証する（OpenID Connect Core 1.0 の 3.1.3.7 節）。
 *
 * 署名の検証と JWKS の解釈は firebase/php-jwt に任せ、自作しない。
 */
final class IdTokenVerifier
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
     * @param  string   $expectedNonce 認可リクエストで送った nonce
     * @param  int|null $now           現在時刻（テスト用。省略時は time()）
     * @return array<string, mixed>    検証済みの claim
     * @throws OidcException 検証に失敗したとき
     */
    public function verify(
        #[\SensitiveParameter] string $idToken,
        ProviderMetadata $metadata,
        string $clientId,
        string $expectedNonce,
        ?int $now = null,
    ): array {
        $now ??= time();
        [$alg, $kid] = self::readHeader($idToken);

        try {
            $claims = $this->decode($idToken, $alg, $kid, $this->jwks->signingKeys($metadata), $now);
        } catch (UnknownKeyException) {
            // IdP が鍵を入れ替えた直後は、キャッシュした JWKS に新しい鍵が無い。1度だけ取り直す。
            try {
                $claims = $this->decode($idToken, $alg, $kid, $this->jwks->signingKeys($metadata, refresh: true), $now);
            } catch (UnknownKeyException) {
                throw new OidcException("the key that signed the ID Token (kid: {$kid}) is not in the JWKS");
            }
        }

        self::assertClaims($claims, $metadata->issuer, $clientId, $expectedNonce);

        return $claims;
    }

    /**
     * ヘッダを読み、受け入れないアルゴリズムを署名の検証より前に弾く。
     *
     * @return array{string, string} [alg, kid]。kid が無ければ空文字
     */
    private static function readHeader(string $idToken): array
    {
        $segments = explode('.', $idToken);
        if (count($segments) !== 3) {
            throw new OidcException('ID Token is not a JWT');
        }
        $decoded = base64_decode(strtr($segments[0], '-_', '+/'), true);
        $header = $decoded === false ? null : json_decode($decoded, true);
        if (!is_array($header) || !is_string($header['alg'] ?? null)) {
            throw new OidcException('cannot read the ID Token header');
        }
        if (!in_array($header['alg'], self::ALLOWED_ALGS, true)) {
            throw new OidcException("ID Token signing algorithm {$header['alg']} is not accepted");
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
    private function decode(string $idToken, string $alg, string $kid, array $jwks, int $now): array
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
            $payload = JWT::decode($idToken, $key);
        } catch (\UnexpectedValueException | \InvalidArgumentException | \DomainException $e) {
            throw new OidcException("ID Token cannot be verified: {$e->getMessage()}", 0, $e);
        } finally {
            JWT::$leeway = $previousLeeway;
            JWT::$timestamp = $previousTimestamp;
        }

        $claims = json_decode((string) json_encode($payload), true);
        if (!is_array($claims)) {
            throw new OidcException('cannot read the ID Token claims');
        }

        return $claims;
    }

    /** @param array<string, mixed> $claims */
    private static function assertClaims(
        array $claims,
        string $issuer,
        string $clientId,
        string $expectedNonce,
    ): void {
        if (($claims['iss'] ?? null) !== $issuer) {
            throw new OidcException('ID Token iss does not match the issuer');
        }

        $sub = $claims['sub'] ?? null;
        if (!is_string($sub) || $sub === '' || strlen($sub) > 255) {
            throw new OidcException('ID Token sub is missing or too long');
        }

        $audiences = is_array($claims['aud'] ?? null) ? $claims['aud'] : [$claims['aud'] ?? null];
        if (!in_array($clientId, $audiences, true)) {
            throw new OidcException('ID Token aud does not contain this client');
        }
        // 宛先が複数あるトークンは、azp がこの client でなければ受け入れない。
        $azp = $claims['azp'] ?? null;
        if ((count($audiences) > 1 || $azp !== null) && $azp !== $clientId) {
            throw new OidcException('ID Token azp does not match this client');
        }

        // exp と iat は必須。ライブラリは「あれば期限を検証する」だけなので、有無はここで見る。
        if (!is_numeric($claims['exp'] ?? null) || !is_numeric($claims['iat'] ?? null)) {
            throw new OidcException('ID Token has no exp or iat');
        }

        $nonce = $claims['nonce'] ?? null;
        if (!is_string($nonce) || !hash_equals($expectedNonce, $nonce)) {
            throw new OidcException('ID Token nonce does not match');
        }
    }
}
