<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Tests\Support;

use Firebase\JWT\JWT;
use LoginOIDC\Oidc\Base64Url;
use LoginOIDC\Oidc\ProviderMetadata;

/** テスト用の IdP。鍵を持ち、ID Token に署名し、Discovery 文書と JWKS を作る。 */
final class TestIdp
{
    public const ISSUER = 'https://idp.example.com/realms/test';
    public const CLIENT_ID = 'avideo';
    public const NOW = 1_800_000_000;

    private \OpenSSLAsymmetricKey $privateKey;

    public function __construct(public readonly string $kid = 'key-1')
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new \RuntimeException('テスト用の鍵を作れない: ' . openssl_error_string());
        }
        $this->privateKey = $key;
    }

    /** @return array<string, mixed> */
    public static function discoveryDocument(): array
    {
        return [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER . '/auth',
            'token_endpoint' => self::ISSUER . '/token',
            'jwks_uri' => self::ISSUER . '/certs',
            'userinfo_endpoint' => self::ISSUER . '/userinfo',
            'end_session_endpoint' => self::ISSUER . '/logout',
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            'id_token_signing_alg_values_supported' => ['RS256'],
        ];
    }

    public static function metadata(): ProviderMetadata
    {
        return ProviderMetadata::fromArray(self::discoveryDocument());
    }

    /**
     * @param  bool $withAlg false にすると、alg を持たない JWK を返す（そういう IdP がある）
     * @return array{keys: list<array<string, string>>}
     */
    public function jwks(bool $withAlg = true): array
    {
        $details = openssl_pkey_get_details($this->privateKey);
        if ($details === false) {
            throw new \RuntimeException('テスト用の鍵を読めない');
        }
        $jwk = [
            'kty' => 'RSA',
            'use' => 'sig',
            'kid' => $this->kid,
            'n' => Base64Url::encode($details['rsa']['n']),
            'e' => Base64Url::encode($details['rsa']['e']),
        ];

        return ['keys' => [$withAlg ? $jwk + ['alg' => 'RS256'] : $jwk]];
    }

    /**
     * 既定では検証を通る ID Token。上書きで壊して使う。
     *
     * @param array<string, mixed> $overrides null を渡した claim は取り除く
     */
    public function idToken(array $overrides = [], string $nonce = 'nonce-1'): string
    {
        $claims = array_filter(array_merge([
            'iss' => self::ISSUER,
            'sub' => 'user-1',
            'aud' => self::CLIENT_ID,
            'exp' => self::NOW + 300,
            'iat' => self::NOW,
            'nonce' => $nonce,
            'sid' => 'session-1',
            'preferred_username' => 'alice',
        ], $overrides), static fn (mixed $v): bool => $v !== null);

        return JWT::encode($claims, $this->privateKey, 'RS256', $this->kid);
    }
}
