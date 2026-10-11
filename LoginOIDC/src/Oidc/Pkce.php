<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

/** Verifier and challenge of PKCE (RFC 7636). Only S256 is used. */
final class Pkce
{
    public function __construct(
        public readonly string $verifier,
        public readonly string $challenge,
    ) {
    }

    public static function generate(): self
    {
        return self::fromVerifier(Base64Url::encode(random_bytes(32)));
    }

    public static function fromVerifier(string $verifier): self
    {
        return new self($verifier, Base64Url::encode(hash('sha256', $verifier, true)));
    }
}
