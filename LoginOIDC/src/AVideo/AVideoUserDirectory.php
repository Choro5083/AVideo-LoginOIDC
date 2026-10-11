<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\AVideo;

use LoginOIDC\User\LocalUser;
use LoginOIDC\User\NewUser;
use LoginOIDC\User\UserDirectory;
use LoginOIDC\UserFlag;

/**
 * Reads and writes AVideo users.
 *
 * Creation is left to AVideo's User class, which knows how to pick the channel name and the defaults.
 * Updates rewrite only the columns concerned, in SQL. User::save() writes every column back,
 * which could clobber columns this plugin does not manage.
 */
final class AVideoUserDirectory implements UserDirectory
{
    /** users.name is VARCHAR(45) */
    private const NAME_MAX_LENGTH = 45;

    public function find(int $userId): ?LocalUser
    {
        $rows = Db::select('SELECT id, user, status FROM users WHERE id = ?', 'i', [$userId]);
        if ($rows === []) {
            return null;
        }

        return new LocalUser((int) $rows[0]['id'], (string) $rows[0]['user'], $rows[0]['status'] === 'a');
    }

    public function usernameExists(string $username): bool
    {
        return Db::select('SELECT 1 FROM users WHERE user = ? LIMIT 1', 's', [$username]) !== [];
    }

    public function create(NewUser $user): int
    {
        // The password is random and known to nobody. It cannot be used for a native sign-in.
        $account = new \User(0, $user->username, encryptPassword(bin2hex(random_bytes(32))));
        $account->setName(self::cleanName($user->name) ?: $user->username);
        if ($user->email !== '') {
            $account->setEmail($user->email);
        }
        $account->setEmailVerified($user->emailVerified ? 1 : 0);

        $userId = (int) $account->save();
        if ($userId <= 0) {
            throw new \RuntimeException("AVideo could not create user {$user->username}");
        }

        // Default user group for new users (the same as AVideo's social sign-in).
        $defaultGroup = (int) (\AVideoPlugin::getObjectData('CustomizeUser')->userDefaultUserGroup->value ?? 0);
        if ($defaultGroup > 0) {
            $this->changeGroups($userId, [$defaultGroup], []);
        }

        return $userId;
    }

    public function updateProfile(int $userId, ?string $name, ?string $email, ?bool $emailVerified): void
    {
        $columns = [];
        $types = '';
        $params = [];

        $name = $name === null ? '' : self::cleanName($name);
        if ($name !== '') {
            $columns[] = 'name = ?';
            $types .= 's';
            $params[] = $name;
        }
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $columns[] = 'email = ?';
            $types .= 's';
            $params[] = $email;
        }
        if ($emailVerified !== null) {
            $columns[] = 'emailVerified = ?';
            $types .= 'i';
            $params[] = $emailVerified ? 1 : 0;
        }
        if ($columns === []) {
            return;
        }

        Db::execute(
            'UPDATE users SET ' . implode(', ', $columns) . ', modified = NOW() WHERE id = ?',
            $types . 'i',
            [...$params, $userId],
        );
    }

    public function setFlags(int $userId, array $flags): void
    {
        $columns = [];
        $params = [];
        foreach ($flags as $name => $enabled) {
            // Column names come from the enum only, because they are put into the SQL text.
            $flag = UserFlag::from($name);
            $columns[] = "{$flag->value} = ?";
            $params[] = $enabled ? 1 : 0;
        }
        if ($columns === []) {
            return;
        }

        Db::execute(
            'UPDATE users SET ' . implode(', ', $columns) . ', modified = NOW() WHERE id = ?',
            str_repeat('i', count($params)) . 'i',
            [...$params, $userId],
        );
    }

    public function changeGroups(int $userId, array $add, array $remove): void
    {
        $current = array_map(
            static fn (array $row): int => (int) $row['users_groups_id'],
            Db::select('SELECT users_groups_id FROM users_has_users_groups WHERE users_id = ?', 'i', [$userId]),
        );
        $existing = array_map(
            static fn (array $row): int => (int) $row['id'],
            Db::select('SELECT id FROM users_groups'),
        );

        foreach (array_diff($add, $current) as $groupId) {
            if (!in_array($groupId, $existing, true)) {
                _error_log("LoginOIDC: user group {$groupId} does not exist, skipped", \AVideoLog::$WARNING);
                continue;
            }
            Db::execute('INSERT INTO users_has_users_groups (users_id, users_groups_id) VALUES (?, ?)', 'ii', [$userId, $groupId]);
        }
        foreach (array_intersect($remove, $current) as $groupId) {
            Db::execute('DELETE FROM users_has_users_groups WHERE users_id = ? AND users_groups_id = ?', 'ii', [$userId, $groupId]);
        }
    }

    public function setActive(int $userId, bool $active): void
    {
        Db::execute('UPDATE users SET status = ?, modified = NOW() WHERE id = ?', 'si', [$active ? 'a' : 'i', $userId]);
    }

    private static function cleanName(string $name): string
    {
        return mb_substr(trim(strip_tags($name)), 0, self::NAME_MAX_LENGTH);
    }
}
