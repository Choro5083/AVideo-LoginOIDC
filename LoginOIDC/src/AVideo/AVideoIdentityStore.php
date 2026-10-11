<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\AVideo;

use LoginOIDC\Sync\LinkedIdentity;
use LoginOIDC\User\DuplicateIdentityException;
use LoginOIDC\User\IdentityStore;

final class AVideoIdentityStore implements IdentityStore
{
    public function findUserId(string $issuer, string $subject): ?int
    {
        $rows = Db::select(
            'SELECT users_id FROM login_oidc_identities WHERE issuer = ? AND subject = ?',
            'ss',
            [$issuer, $subject],
        );

        return $rows === [] ? null : (int) $rows[0]['users_id'];
    }

    public function link(int $userId, string $issuer, string $subject): void
    {
        try {
            Db::execute(
                'INSERT INTO login_oidc_identities (users_id, issuer, subject, created, modified)'
                . ' VALUES (?, ?, ?, NOW(), NOW())',
                'iss',
                [$userId, $issuer, $subject],
            );
        } catch (DbException $e) {
            if ($e->getCode() === DbException::DUPLICATE_ENTRY) {
                throw new DuplicateIdentityException($e->getMessage(), 0, $e);
            }
            throw $e;
        }
    }

    public function recordLogin(int $userId): void
    {
        Db::execute('UPDATE login_oidc_identities SET last_login = NOW(), modified = NOW() WHERE users_id = ?', 'i', [$userId]);
    }

    public function allLinked(string $issuer): array
    {
        return array_map(
            static fn (array $row): LinkedIdentity => new LinkedIdentity(
                (int) $row['users_id'],
                (string) $row['user'],
                (string) $row['subject'],
                $row['status'] === 'a',
                (int) $row['disabled_by_sync'] === 1,
            ),
            Db::select(
                'SELECT i.users_id, i.subject, i.disabled_by_sync, u.user, u.status'
                . ' FROM login_oidc_identities i JOIN users u ON u.id = i.users_id'
                . ' WHERE i.issuer = ? ORDER BY i.users_id',
                's',
                [$issuer],
            ),
        );
    }

    public function setDisabledBySync(int $userId, bool $disabled): void
    {
        Db::execute(
            'UPDATE login_oidc_identities SET disabled_by_sync = ?, modified = NOW() WHERE users_id = ?',
            'ii',
            [$disabled ? 1 : 0, $userId],
        );
    }

    public function transactional(callable $work): mixed
    {
        $connection = Db::connection();
        $connection->begin_transaction();
        try {
            $result = $work();
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $connection->rollback();
            throw $e;
        }
    }
}
