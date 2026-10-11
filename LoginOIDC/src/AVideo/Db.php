<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\AVideo;

/**
 * Runs prepared statements on the database connection AVideo has open.
 *
 * AVideo's sqlDAL caches results and swallows failures in places. For this plugin's
 * tables and for updates limited to specific columns, the connection is used directly.
 * It is the same connection, so the statements join AVideo's own transaction.
 */
final class Db
{
    public static function connection(): \mysqli
    {
        global $global;

        if (!_mysql_is_open()) {
            _mysql_connect();
        }
        if (!($global['mysqli'] ?? null) instanceof \mysqli) {
            throw new DbException('no database connection');
        }

        return $global['mysqli'];
    }

    /**
     * @param  list<int|string|null> $params
     * @return list<array<string, mixed>>
     * @throws DbException
     */
    public static function select(string $sql, string $types = '', array $params = []): array
    {
        $statement = self::run($sql, $types, $params);
        $result = $statement->get_result();
        if ($result === false) {
            throw new DbException("query returned no result set: {$statement->error}", $statement->errno);
        }
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $statement->close();

        return $rows;
    }

    /**
     * @param  list<int|string|null> $params
     * @return int number of affected rows
     * @throws DbException
     */
    public static function execute(string $sql, string $types = '', array $params = []): int
    {
        $statement = self::run($sql, $types, $params);
        $affected = (int) $statement->affected_rows;
        $statement->close();

        return $affected;
    }

    /**
     * @param  list<int|string|null> $params
     * @throws DbException
     */
    private static function run(string $sql, string $types, array $params): \mysqli_stmt
    {
        $connection = self::connection();
        // Whether the connection throws or returns false on errors depends on AVideo's settings. Handle both.
        try {
            $statement = $connection->prepare($sql);
            if ($statement === false) {
                throw new DbException("cannot prepare statement: {$connection->error}", $connection->errno);
            }
            if ($params !== [] && !$statement->bind_param($types, ...$params)) {
                throw new DbException("cannot bind parameters: {$statement->error}", $statement->errno);
            }
            if (!$statement->execute()) {
                throw new DbException("cannot execute statement: {$statement->error}", $statement->errno);
            }
        } catch (\mysqli_sql_exception $e) {
            throw new DbException($e->getMessage(), $e->getCode(), $e);
        }

        return $statement;
    }
}
