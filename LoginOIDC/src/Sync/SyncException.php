<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Sync;

/** The sync cannot continue. When this is thrown, nothing has been changed. */
final class SyncException extends \RuntimeException
{
}
