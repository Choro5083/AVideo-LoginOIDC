<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

/**
 * The OIDC exchange failed.
 *
 * The message is for the admin's log. It is not shown to the user as it is,
 * because it can contain the provider's response and details of the verification.
 */
final class OidcException extends \RuntimeException
{
}
