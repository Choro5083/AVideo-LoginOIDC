<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

/**
 * トークンエンドポイントで、クライアント自身の身元を示す方法。
 *
 * 方式を足すとき（private_key_jwt など）は、この実装を1つ増やせば済むようにしてある。
 */
interface ClientAuthenticator
{
    /** IdP が Discovery で示す方式名（token_endpoint_auth_methods_supported の値）。 */
    public function method(): string;

    /**
     * リクエストに認証の情報を足して返す。
     *
     * @param  array<string, string> $headers
     * @param  array<string, string> $form
     * @return array{array<string, string>, array<string, string>} [headers, form]
     */
    public function apply(array $headers, array $form): array;
}
