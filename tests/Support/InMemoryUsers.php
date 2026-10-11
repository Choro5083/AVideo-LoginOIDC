<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Tests\Support;

use LoginOIDC\User\DuplicateIdentityException;
use LoginOIDC\User\IdentityStore;
use LoginOIDC\User\LocalUser;
use LoginOIDC\User\NewUser;
use LoginOIDC\User\UserDirectory;

/** ユーザと対応を配列で持つ。UserDirectory と IdentityStore の両方を兼ねる。 */
final class InMemoryUsers implements UserDirectory, IdentityStore
{
    /** @var array<int, array{username: string, name: string, email: string, emailVerified: bool, active: bool, flags: array<string, bool>, groups: list<int>}> */
    public array $users = [];

    /** @var array<string, int> "issuer\nsub" → ユーザの ID */
    public array $links = [];

    /** @var list<int> */
    public array $logins = [];

    /** link() の直前に1度だけ呼ぶ。同時に来た別のリクエストが先に対応を作った状況を再現する。 */
    public ?\Closure $beforeLink = null;

    private ?\Closure $committedElsewhere = null;

    private int $nextId = 1;

    public function add(string $username, bool $active = true): int
    {
        $id = $this->nextId++;
        $this->users[$id] = [
            'username' => $username, 'name' => '', 'email' => '', 'emailVerified' => false,
            'active' => $active, 'flags' => [], 'groups' => [],
        ];

        return $id;
    }

    public function find(int $userId): ?LocalUser
    {
        $user = $this->users[$userId] ?? null;

        return $user === null ? null : new LocalUser($userId, $user['username'], $user['active']);
    }

    public function usernameExists(string $username): bool
    {
        return in_array($username, array_column($this->users, 'username'), true);
    }

    public function create(NewUser $user): int
    {
        $id = $this->add($user->username);
        $this->users[$id]['name'] = $user->name;
        $this->users[$id]['email'] = $user->email;
        $this->users[$id]['emailVerified'] = $user->emailVerified;

        return $id;
    }

    public function updateProfile(int $userId, ?string $name, ?string $email, ?bool $emailVerified): void
    {
        foreach (['name' => $name, 'email' => $email, 'emailVerified' => $emailVerified] as $key => $value) {
            if ($value !== null) {
                $this->users[$userId][$key] = $value;
            }
        }
    }

    public function setFlags(int $userId, array $flags): void
    {
        $this->users[$userId]['flags'] = array_merge($this->users[$userId]['flags'], $flags);
    }

    public function changeGroups(int $userId, array $add, array $remove): void
    {
        $groups = array_diff(array_unique([...$this->users[$userId]['groups'], ...$add]), $remove);
        sort($groups);
        $this->users[$userId]['groups'] = array_values($groups);
    }

    public function findUserId(string $issuer, string $subject): ?int
    {
        return $this->links["{$issuer}\n{$subject}"] ?? null;
    }

    public function link(int $userId, string $issuer, string $subject): void
    {
        if ($this->beforeLink !== null) {
            $hook = $this->beforeLink;
            $this->beforeLink = null;
            $this->committedElsewhere = $hook;
            $hook();
        }
        $key = "{$issuer}\n{$subject}";
        if (isset($this->links[$key]) || in_array($userId, $this->links, true)) {
            throw new DuplicateIdentityException('duplicate');
        }
        $this->links[$key] = $userId;
    }

    public function recordLogin(int $userId): void
    {
        $this->logins[] = $userId;
    }

    public function transactional(callable $work): mixed
    {
        $snapshot = [$this->users, $this->links, $this->nextId];
        try {
            return $work();
        } catch (\Throwable $e) {
            [$this->users, $this->links, $this->nextId] = $snapshot;
            // 別のリクエストが確定させた分は、こちらの取り消しでは消えない。
            if ($this->committedElsewhere !== null) {
                ($this->committedElsewhere)();
                $this->committedElsewhere = null;
            }
            throw $e;
        }
    }
}
