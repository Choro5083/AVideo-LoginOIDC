<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Session;

/** 自動リダイレクトの判定に使う、リクエストの情報。 */
final class PageRequest
{
    public function __construct(
        public readonly string $method,
        /** 実行されているスクリプトのパス（例: /view/user.php） */
        public readonly string $scriptName,
        public readonly string $accept,
        /** JavaScript からのリクエストか（X-Requested-With: XMLHttpRequest） */
        public readonly bool $ajax,
        public readonly string $remoteAddress,
    ) {
    }
}
