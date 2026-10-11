<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Verifies the signature and the expiry of a JWT signed by the provider (ID Token, Logout Token).
 *
 * Signature verification and JWKS parsing are left to firebase/php-jwt. They are not reimplemented here.
 * The claims themselves (iss, aud and so on) are checked by the caller, per kind of token.
 */
final class JwtVerifier
{
    /**
     * Signing algorithms that are accepted: only those verified with a public key.
     * Accepting shared-key algorithms (HS*) would open the door to using the public key as the shared key.
     */
    private const ALLOWED_ALGS = ['RS256', 'RS384', 'RS512', 'ES256', 'ES384', 'EdDSA'];

    public function __construct(
        private readonly JwksProvider $jwks,
        private readonly int $clockSkewSeconds,
    ) {
    }

    /**
     * @param  string $what kind of token, for error messages
     * @return array<string, mixed> claims whose signature was verified
     * @throws OidcException when verification fails
     */
    public function verify(#[\SensitiveParameter] string $jwt, ProviderMetadata $metadata, int $now, string $what): array
    {
        [$alg, $kid] = self::readHeader($jwt, $what);

        try {
            return $this->decode($jwt, $alg, $kid, $this->jwks->signingKeys($metadata), $now, $what);
        } catch (UnknownKeyException) {
            // Right after the provider rotates its keys, the cached JWKS lacks the new one. Refetch once.
            try {
                return $this->decode($jwt, $alg, $kid, $this->jwks->signingKeys($metadata, refresh: true), $now, $what);
            } catch (UnknownKeyException) {
                throw new OidcException("the key that signed the {$what} (kid: {$kid}) is not in the JWKS");
            }
        }
    }

    /**
     * Reads the header and rejects unaccepted algorithms before any signature is verified.
     *
     * @return array{string, string} [alg, kid]. kid is an empty string when absent
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
     * Verifies the signature and the expiry, and returns the claims.
     *
     * @param  array{keys: list<array<string, mixed>>} $jwks
     * @return array<string, mixed>
     * @throws UnknownKeyException when no key matches the kid
     * @throws OidcException       on any other verification failure
     */
    private function decode(string $jwt, string $alg, string $kid, array $jwks, int $now, string $what): array
    {
        // leeway and timestamp are static settings of the library, and AVideo uses the same class.
        // Change them only for this verification and always restore them.
        $previousLeeway = JWT::$leeway;
        $previousTimestamp = JWT::$timestamp;
        JWT::$leeway = $this->clockSkewSeconds;
        JWT::$timestamp = $now;
        try {
            // Some providers publish JWKS keys without alg, so the token's alg (already allowlisted) is the default.
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
