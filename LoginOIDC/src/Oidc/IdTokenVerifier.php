<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

/**
 * ID Token を検証する（OpenID Connect Core 1.0 の 3.1.3.7 節）。
 *
 * 署名と有効期限の検証は JwtVerifier が行う。ここでは claim の内容を検証する。
 */
final class IdTokenVerifier
{
    public function __construct(private readonly JwtVerifier $jwt)
    {
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
        $claims = $this->jwt->verify($idToken, $metadata, $now ?? time(), 'ID Token');

        self::assertClaims($claims, $metadata->issuer, $clientId, $expectedNonce);

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
