<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Session;

/** What the automatic redirect needs to know about a request. */
final class PageRequest
{
    public function __construct(
        public readonly string $method,
        /** path of the running script (for example /view/user.php) */
        public readonly string $scriptName,
        public readonly string $accept,
        /** whether the request comes from JavaScript (X-Requested-With: XMLHttpRequest) */
        public readonly bool $ajax,
        public readonly string $remoteAddress,
    ) {
    }
}
