<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC;

/** トークンエンドポイントでのクライアント認証の方式。 */
enum ClientAuthMethod: string
{
    case SecretBasic = 'client_secret_basic';
    case SecretPost = 'client_secret_post';
}
