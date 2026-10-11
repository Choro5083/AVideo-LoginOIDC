<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\AVideo;

/** A statement failed. The code is the MySQL / MariaDB error number. */
final class DbException extends \RuntimeException
{
    public const DUPLICATE_ENTRY = 1062;
}
