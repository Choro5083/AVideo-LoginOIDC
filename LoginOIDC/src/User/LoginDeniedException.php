<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\User;

/**
 * The provider authenticated the user, but signing in to AVideo is not allowed.
 *
 * The message is for the admin's log.
 */
final class LoginDeniedException extends \RuntimeException
{
}
