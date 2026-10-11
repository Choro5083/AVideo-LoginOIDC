<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC;

/** How the client authenticates at the token endpoint. */
enum ClientAuthMethod: string
{
    case SecretBasic = 'client_secret_basic';
    case SecretPost = 'client_secret_post';
}
