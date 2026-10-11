<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

/** The part of the provider's discovery document this plugin uses. */
final class ProviderMetadata
{
    /**
     * @param list<string> $tokenEndpointAuthMethods
     * @param list<string> $idTokenSigningAlgs
     */
    public function __construct(
        public readonly string $issuer,
        public readonly string $authorizationEndpoint,
        public readonly string $tokenEndpoint,
        public readonly string $jwksUri,
        public readonly string $userinfoEndpoint,
        public readonly string $endSessionEndpoint,
        public readonly array $tokenEndpointAuthMethods,
        public readonly array $idTokenSigningAlgs,
    ) {
    }

    /**
     * @param array<string, mixed> $document
     * @throws OidcException when a required member is missing or malformed
     */
    public static function fromArray(array $document): self
    {
        return new self(
            issuer: self::requiredUrl($document, 'issuer'),
            authorizationEndpoint: self::requiredUrl($document, 'authorization_endpoint'),
            tokenEndpoint: self::requiredUrl($document, 'token_endpoint'),
            jwksUri: self::requiredUrl($document, 'jwks_uri'),
            userinfoEndpoint: self::optionalUrl($document, 'userinfo_endpoint'),
            endSessionEndpoint: self::optionalUrl($document, 'end_session_endpoint'),
            // The default when omitted is client_secret_basic (OpenID Connect Discovery 1.0, section 3)
            tokenEndpointAuthMethods: self::stringList($document, 'token_endpoint_auth_methods_supported')
                ?: ['client_secret_basic'],
            idTokenSigningAlgs: self::stringList($document, 'id_token_signing_alg_values_supported'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'issuer' => $this->issuer,
            'authorization_endpoint' => $this->authorizationEndpoint,
            'token_endpoint' => $this->tokenEndpoint,
            'jwks_uri' => $this->jwksUri,
            'userinfo_endpoint' => $this->userinfoEndpoint,
            'end_session_endpoint' => $this->endSessionEndpoint,
            'token_endpoint_auth_methods_supported' => $this->tokenEndpointAuthMethods,
            'id_token_signing_alg_values_supported' => $this->idTokenSigningAlgs,
        ];
    }

    /** @param array<string, mixed> $document */
    private static function requiredUrl(array $document, string $name): string
    {
        $value = self::optionalUrl($document, $name);
        if ($value === '') {
            throw new OidcException("discovery document has no {$name}");
        }

        return $value;
    }

    /** @param array<string, mixed> $document */
    private static function optionalUrl(array $document, string $name): string
    {
        $value = $document[$name] ?? '';
        if (!is_string($value)) {
            throw new OidcException("discovery document {$name} is not a string");
        }
        if ($value !== '' && !in_array(parse_url($value, PHP_URL_SCHEME), ['https', 'http'], true)) {
            throw new OidcException("discovery document {$name} is not an http(s) URL");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed> $document
     * @return list<string>
     */
    private static function stringList(array $document, string $name): array
    {
        $value = $document[$name] ?? [];
        if (!is_array($value)) {
            throw new OidcException("discovery document {$name} is not an array");
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
