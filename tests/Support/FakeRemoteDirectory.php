<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Tests\Support;

use LoginOIDC\Sync\RemoteDirectory;
use LoginOIDC\Sync\RemoteUser;
use LoginOIDC\Sync\SyncException;

final class FakeRemoteDirectory implements RemoteDirectory
{
    /** @var array<string, RemoteUser> sub → ユーザ。無ければ「存在しない」 */
    public array $users = [];

    /** @var list<string> 調べようとすると API が失敗する sub */
    public array $failing = [];

    /** @param list<string> $roles */
    public function put(string $subject, array $roles = [], bool $enabled = true, string $name = '', string $email = ''): void
    {
        $this->users[$subject] = new RemoteUser($enabled, $name, $email, null, $roles, $roles);
    }

    public function lookup(string $subject): ?RemoteUser
    {
        if (in_array($subject, $this->failing, true)) {
            throw new SyncException("Admin API request failed for {$subject}");
        }

        return $this->users[$subject] ?? null;
    }
}
