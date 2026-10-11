<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

/**
 * 認可リクエストを送ってからコールバックが来るまで、セッションに持っておく値。
 */
final class PendingLogin
{
    /** これより古いものは受け付けない。IdP のログイン画面で放置された分を捨てる。 */
    public const MAX_AGE_SECONDS = 600;

    public function __construct(
        public readonly string $state,
        public readonly string $nonce,
        #[\SensitiveParameter] public readonly string $codeVerifier,
        public readonly string $returnTo,
        public readonly int $createdAt,
    ) {
    }

    /** @return array<string, string|int> */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'nonce' => $this->nonce,
            'codeVerifier' => $this->codeVerifier,
            'returnTo' => $this->returnTo,
            'createdAt' => $this->createdAt,
        ];
    }

    /** セッションから読んだ値を戻す。形が違えば null。 */
    public static function fromArray(mixed $data): ?self
    {
        if (
            !is_array($data)
            || !is_string($data['state'] ?? null) || $data['state'] === ''
            || !is_string($data['nonce'] ?? null) || $data['nonce'] === ''
            || !is_string($data['codeVerifier'] ?? null) || $data['codeVerifier'] === ''
            || !is_string($data['returnTo'] ?? null)
            || !is_int($data['createdAt'] ?? null)
        ) {
            return null;
        }

        return new self($data['state'], $data['nonce'], $data['codeVerifier'], $data['returnTo'], $data['createdAt']);
    }
}
