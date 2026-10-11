<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC;

/** How far native sign-in (username and password) is allowed. */
enum NativeLoginMode: string
{
    /** no restriction */
    case Allow = 'allow';
    /** denied only for users linked to the provider */
    case DenyLinked = 'denyLinked';
    /** allowed only for the users in the allowlist */
    case Allowlist = 'allowlist';
}
