<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\AVideo;

/**
 * AVideo が開いている DB 接続で、プリペアドステートメントを実行する。
 *
 * 本体の sqlDAL は結果をキャッシュしたり、失敗を握りつぶしたりする箇所がある。
 * このプラグインのテーブルと、列を絞った更新には、接続を直接使う。
 * 接続は本体と同じものなので、本体の処理と同じトランザクションに入る。
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
     * @return int 変更された行数
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
        // 接続のエラーの扱い（例外を投げるか、false を返すか）は本体の設定による。両方を受ける。
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
