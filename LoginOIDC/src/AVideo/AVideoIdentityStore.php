<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\AVideo;

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
