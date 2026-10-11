<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\User;

use LoginOIDC\Sync\LinkedIdentity;

/** Maps users at the provider (issuer and sub) to AVideo users. */
interface IdentityStore
{
    public function findUserId(string $issuer, string $subject): ?int;

    /** @throws DuplicateIdentityException when the (issuer, sub) or the user is already linked */
    public function link(int $userId, string $issuer, string $subject): void;

    public function recordLogin(int $userId): void;

    /**
     * Returns every user linked to this issuer.
     *
     * @return list<LinkedIdentity>
     */
    public function allLinked(string $issuer): array;

    /** Records whether the sync disabled the user. */
    public function setDisabledBySync(int $userId, bool $disabled): void;

    /**
     * Wraps the work in a transaction. On an exception, rolls back and rethrows.
     *
     * @template T
     * @param  callable(): T $work
     * @return T
     */
    public function transactional(callable $work): mixed;
}
