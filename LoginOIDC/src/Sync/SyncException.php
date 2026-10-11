<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Sync;

/** 同期を続けられない。この例外が出たときは、何も変更していない。 */
final class SyncException extends \RuntimeException
{
}
