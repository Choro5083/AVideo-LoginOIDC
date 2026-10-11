<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\User;

use LoginOIDC\UserFlag;

/** Reads and writes AVideo users. */
interface UserDirectory
{
    public function find(int $userId): ?LocalUser;

    public function usernameExists(string $username): bool;

    /** Creates a user and returns the ID. The password is random and known to nobody. */
    public function create(NewUser $user): int;

    /**
     * Updates only the fields given. null leaves a field untouched.
     */
    public function updateProfile(int $userId, ?string $name, ?string $email, ?bool $emailVerified): void;

    /**
     * Updates only the flags given. Flags that are not passed are left untouched.
     *
     * @param array<string, bool> $flags UserFlag value → whether to grant it
     */
    public function setFlags(int $userId, array $flags): void;

    /**
     * Adds and removes user groups. Groups that are not passed are left untouched.
     *
     * @param list<int> $add
     * @param list<int> $remove
     */
    public function changeGroups(int $userId, array $add, array $remove): void;

    /** Enables or disables a user. Never deletes. */
    public function setActive(int $userId, bool $active): void;
}
