<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

/** What a verified Logout Token points at. At least one of sid and sub is present. */
final class LogoutNotice
{
    public function __construct(
        public readonly string $issuer,
        /** an empty string when absent */
        public readonly string $subject,
        /** ID of the provider session. An empty string when absent */
        public readonly string $sessionId,
    ) {
    }
}
