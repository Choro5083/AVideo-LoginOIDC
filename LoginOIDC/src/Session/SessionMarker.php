<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Session;

/**
 * IdP 経由でログインしたことを示す、PHP のセッションに置く印。
 *
 * これがあるセッションだけを、ログアウトの連動（RP-Initiated、Back-Channel）と
 * 無効化の確認の対象にする。
 */
final class SessionMarker
{
    public function __construct(
        public readonly int $userId,
        /** IdP のセッションの ID。IdP が返さなければ空文字 */
        public readonly string $sessionId,
        public readonly int $loginAt,
        /** ログアウト時に id_token_hint として IdP に渡す */
        #[\SensitiveParameter] public readonly string $idToken,
    ) {
    }

    /** @return array<string, string|int> */
    public function toArray(): array
    {
        return [
            'userId' => $this->userId,
            'sessionId' => $this->sessionId,
            'loginAt' => $this->loginAt,
            'idToken' => $this->idToken,
        ];
    }

    /** セッションから読んだ値を戻す。形が違えば null。 */
    public static function fromArray(mixed $data): ?self
    {
        if (
            !is_array($data)
            || !is_int($data['userId'] ?? null)
            || !is_string($data['sessionId'] ?? null)
            || !is_int($data['loginAt'] ?? null)
            || !is_string($data['idToken'] ?? null)
        ) {
            return null;
        }

        return new self($data['userId'], $data['sessionId'], $data['loginAt'], $data['idToken']);
    }
}
