<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

/**
 * Verifies an ID Token (OpenID Connect Core 1.0, section 3.1.3.7).
 *
 * JwtVerifier checks the signature and the expiry. The claims themselves are checked here.
 */
final class IdTokenVerifier
{
    public function __construct(private readonly JwtVerifier $jwt)
    {
    }

    /**
     * @param  string   $expectedNonce nonce sent in the authorization request
     * @param  int|null $now           current time (for tests; time() when omitted)
     * @return array<string, mixed>    verified claims
     * @throws OidcException when verification fails
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
        // A token for several audiences is accepted only when azp is this client.
        $azp = $claims['azp'] ?? null;
        if ((count($audiences) > 1 || $azp !== null) && $azp !== $clientId) {
            throw new OidcException('ID Token azp does not match this client');
        }

        // exp and iat are required. The library only checks the expiry when present, so their presence is checked here.
        if (!is_numeric($claims['exp'] ?? null) || !is_numeric($claims['iat'] ?? null)) {
            throw new OidcException('ID Token has no exp or iat');
        }

        $nonce = $claims['nonce'] ?? null;
        if (!is_string($nonce) || !hash_equals($expectedNonce, $nonce)) {
            throw new OidcException('ID Token nonce does not match');
        }
    }
}
