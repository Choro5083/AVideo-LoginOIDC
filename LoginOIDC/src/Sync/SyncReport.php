<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Sync;

/** 同期の結果。dry-run のときは「こうなるはずだった」内容。 */
final class SyncReport
{
    /**
     * @param list<string> $disabled        無効化したユーザ（username と理由）
     * @param list<string> $enabled         有効に戻したユーザ
     * @param list<string> $sessionsRevoked ログインの条件を満たさなくなり、セッションを終わらせたユーザ
     */
    public function __construct(
        public readonly bool $dryRun,
        public readonly int $checked,
        public readonly array $disabled,
        public readonly array $enabled,
        public readonly array $sessionsRevoked,
        public readonly int $refreshed,
        /** dry-run で、実際の実行なら止まる理由。止まらなければ null */
        public readonly ?string $refusal = null,
    ) {
    }

    public function summary(): string
    {
        return sprintf(
            '%schecked %d, disabled %d, re-enabled %d, sessions revoked %d, refreshed %d',
            $this->dryRun ? '[dry-run] ' : '',
            $this->checked,
            count($this->disabled),
            count($this->enabled),
            count($this->sessionsRevoked),
            $this->refreshed,
        );
    }
}
