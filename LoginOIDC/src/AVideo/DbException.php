<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\AVideo;

/** SQL の実行に失敗した。code は MySQL / MariaDB のエラー番号。 */
final class DbException extends \RuntimeException
{
    public const DUPLICATE_ENTRY = 1062;
}
