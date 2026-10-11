<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\User;

/**
 * IdP での認証は通ったが、AVideo へのログインは認めない。
 *
 * メッセージは管理者向けのログ用。
 */
final class LoginDeniedException extends \RuntimeException
{
}
