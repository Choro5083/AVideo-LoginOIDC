<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

/** The JWKS has no key for the token's kid. Used as the cue to refetch the JWKS. */
final class UnknownKeyException extends \RuntimeException
{
}
