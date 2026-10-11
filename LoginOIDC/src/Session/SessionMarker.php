<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Session;

/**
 * A marker in the PHP session saying that the user signed in through the provider.
 *
 * Only sessions that carry it take part in logout linking (RP-Initiated, Back-Channel)
 * and in the revocation check.
 */
final class SessionMarker
{
    public function __construct(
        public readonly int $userId,
        /** ID of the provider session. An empty string when the provider sent none */
        public readonly string $sessionId,
        public readonly int $loginAt,
        /** passed to the provider as id_token_hint at logout */
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

    /** Restores a value read from the session. null when the shape is wrong. */
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
