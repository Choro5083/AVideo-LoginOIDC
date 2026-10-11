<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

/**
 * How the client proves its own identity at the token endpoint.
 *
 * Adding a method (private_key_jwt, for example) only takes one more implementation of this.
 */
interface ClientAuthenticator
{
    /** Name of the method as the provider lists it in discovery (token_endpoint_auth_methods_supported). */
    public function method(): string;

    /**
     * Returns the request with the authentication added.
     *
     * @param  array<string, string> $headers
     * @param  array<string, string> $form
     * @return array{array<string, string>, array<string, string>} [headers, form]
     */
    public function apply(array $headers, array $form): array;
}
