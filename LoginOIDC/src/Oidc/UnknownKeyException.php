<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

/** トークンの kid に合う鍵が JWKS に無い。JWKS を取り直すきっかけにする。 */
final class UnknownKeyException extends \RuntimeException
{
}
