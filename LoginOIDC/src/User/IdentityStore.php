<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\User;

/** IdP のユーザ（issuer と sub）と AVideo のユーザの対応。 */
interface IdentityStore
{
    public function findUserId(string $issuer, string $subject): ?int;

    /** @throws DuplicateIdentityException すでに同じ (issuer, sub) か、同じユーザの対応があるとき */
    public function link(int $userId, string $issuer, string $subject): void;

    public function recordLogin(int $userId): void;

    /**
     * 処理をトランザクションで包む。例外が出たら取り消して投げ直す。
     *
     * @template T
     * @param  callable(): T $work
     * @return T
     */
    public function transactional(callable $work): mixed;
}
