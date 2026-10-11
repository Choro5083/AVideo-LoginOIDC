<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

/** client_secret_basic。ID とシークレットを Authorization ヘッダで送る。 */
final class SecretBasicAuthenticator implements ClientAuthenticator
{
    public function __construct(
        private readonly string $clientId,
        #[\SensitiveParameter] private readonly string $clientSecret,
    ) {
    }

    public function method(): string
    {
        return 'client_secret_basic';
    }

    public function apply(array $headers, array $form): array
    {
        // ID とシークレットは、Base64 にする前にフォームの形式でエンコードする
        // （RFC 6749 の 2.3.1 節）。記号を含むシークレットで食い違うため省けない。
        $credentials = urlencode($this->clientId) . ':' . urlencode($this->clientSecret);

        return [$headers + ['Authorization' => 'Basic ' . base64_encode($credentials)], $form];
    }
}
