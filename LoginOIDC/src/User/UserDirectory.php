<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\User;

use LoginOIDC\UserFlag;

/** AVideo のユーザの読み書き。 */
interface UserDirectory
{
    public function find(int $userId): ?LocalUser;

    public function usernameExists(string $username): bool;

    /** ユーザを作り、ID を返す。パスワードには、誰にも知らせない乱数を入れる。 */
    public function create(NewUser $user): int;

    /**
     * 渡した項目だけを更新する。null の項目には触れない。
     */
    public function updateProfile(int $userId, ?string $name, ?string $email, ?bool $emailVerified): void;

    /**
     * 渡したフラグだけを更新する。渡さなかったフラグには触れない。
     *
     * @param array<string, bool> $flags UserFlag の値 → 付けるかどうか
     */
    public function setFlags(int $userId, array $flags): void;

    /**
     * user group を足し引きする。渡さなかった group には触れない。
     *
     * @param list<int> $add
     * @param list<int> $remove
     */
    public function changeGroups(int $userId, array $add, array $remove): void;
}
