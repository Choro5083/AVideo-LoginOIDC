<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

/**
 * OIDC の手続きが失敗した。
 *
 * メッセージは管理者向けのログ用。利用者の画面にはそのまま出さない
 * （IdP の応答や検証の詳細を含むことがあるため）。
 */
final class OidcException extends \RuntimeException
{
}
