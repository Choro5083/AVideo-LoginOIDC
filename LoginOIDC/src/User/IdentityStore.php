<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\User;

use LoginOIDC\Sync\LinkedIdentity;

/** IdP のユーザ（issuer と sub）と AVideo のユーザの対応。 */
interface IdentityStore
{
    public function findUserId(string $issuer, string $subject): ?int;

    /** @throws DuplicateIdentityException すでに同じ (issuer, sub) か、同じユーザの対応があるとき */
    public function link(int $userId, string $issuer, string $subject): void;

    public function recordLogin(int $userId): void;

    /**
     * この issuer に紐付いたユーザをすべて返す。
     *
     * @return list<LinkedIdentity>
     */
    public function allLinked(string $issuer): array;

    /** 同期が無効化したユーザかどうかを記録する。 */
    public function setDisabledBySync(int $userId, bool $disabled): void;

    /**
     * 処理をトランザクションで包む。例外が出たら取り消して投げ直す。
     *
     * @template T
     * @param  callable(): T $work
     * @return T
     */
    public function transactional(callable $work): mixed;
}
