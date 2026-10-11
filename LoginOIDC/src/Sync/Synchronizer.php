<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Sync;

use LoginOIDC\Session\SessionRevoker;
use LoginOIDC\Settings;
use LoginOIDC\User\IdentityStore;
use LoginOIDC\User\RoleMapper;
use LoginOIDC\User\UserDirectory;

/**
 * IdP 側の変化（削除、無効化、権限の剥奪、属性の変更）を AVideo に反映する。
 *
 * 対象は IdP に紐付いたユーザだけ。ユーザは作らない（作成は初回ログイン時だけ）。
 * 行うのはユーザの無効化までで、動画には触れない。
 */
final class Synchronizer
{
    public function __construct(
        private readonly Settings $settings,
        private readonly IdentityStore $identities,
        private readonly UserDirectory $users,
        private readonly SessionRevoker $sessions,
        private readonly RemoteDirectory $remote,
    ) {
    }

    /**
     * @param  int|null $maxDisableRatio 1回で無効化してよい割合（%）。null なら設定の値
     * @throws SyncException 続けられないとき。何も変更していない
     */
    public function run(bool $dryRun, ?int $maxDisableRatio = null, ?int $now = null): SyncReport
    {
        $now ??= time();
        $linked = $this->identities->allLinked($this->settings->issuer);

        // 先に全員の状態を調べ終える。途中で API が失敗したら、何も変更せずに止める。
        // 通信の失敗を「ユーザがいない」と取り違えて無効化するのを防ぐ。
        $remote = [];
        foreach ($linked as $identity) {
            $remote[$identity->userId] = $this->remote->lookup($identity->subject);
        }

        $toDisable = [];
        $toEnable = [];
        $toRefresh = [];
        foreach ($linked as $identity) {
            $state = $remote[$identity->userId];
            $usable = $state !== null && $state->enabled;
            if ($identity->active && !$usable) {
                $toDisable[] = [$identity, $state === null ? 'deleted at the provider' : 'disabled at the provider'];
            } elseif (!$identity->active && $identity->disabledBySync && $usable) {
                // 有効に戻すのは、同期が無効化したユーザだけ。管理者が手で無効化したユーザは戻さない。
                $toEnable[] = $identity;
                $toRefresh[] = [$identity, $state];
            } elseif ($identity->active && $state !== null) {
                $toRefresh[] = [$identity, $state];
            }
        }

        $refusal = self::disableRatioProblem(
            count($toDisable),
            count(array_filter($linked, static fn (LinkedIdentity $i): bool => $i->active)),
            $maxDisableRatio ?? $this->settings->syncMaxDisableRatio,
        );
        // dry-run では止めずに、止まる理由を結果に載せる。何が起きるかを確かめるための実行なので。
        if ($refusal !== null && !$dryRun) {
            throw new SyncException($refusal);
        }

        $sessionsRevoked = [];
        foreach ($toRefresh as [$identity, $state]) {
            if (!RoleMapper::meetsRequirement($this->settings, $this->claimsOf($state))) {
                $sessionsRevoked[] = $identity->username;
            }
        }

        if (!$dryRun) {
            foreach ($toDisable as [$identity]) {
                $this->users->setActive($identity->userId, false);
                $this->identities->setDisabledBySync($identity->userId, true);
                $this->sessions->revokeAllOfUser($identity->userId, $now);
            }
            foreach ($toEnable as $identity) {
                $this->users->setActive($identity->userId, true);
                $this->identities->setDisabledBySync($identity->userId, false);
            }
            foreach ($toRefresh as [$identity, $state]) {
                $this->refresh($identity, $state, $now);
            }
        }

        return new SyncReport(
            dryRun: $dryRun,
            checked: count($linked),
            disabled: array_map(
                static fn (array $entry): string => "{$entry[0]->username} ({$entry[1]})",
                $toDisable,
            ),
            enabled: array_map(static fn (LinkedIdentity $i): string => $i->username, $toEnable),
            sessionsRevoked: $sessionsRevoked,
            refreshed: count($toRefresh),
            refusal: $refusal,
        );
    }

    private function refresh(LinkedIdentity $identity, RemoteUser $state, int $now): void
    {
        $claims = $this->claimsOf($state);

        $this->users->updateProfile(
            $identity->userId,
            $this->settings->updateName && $state->name !== '' ? $state->name : null,
            $this->settings->updateEmail && $state->email !== '' ? $state->email : null,
            $this->settings->updateEmailVerified ? $state->emailVerified : null,
        );
        $this->users->setFlags($identity->userId, RoleMapper::flags($this->settings, $claims));
        [$add, $remove] = RoleMapper::groups($this->settings, $claims);
        $this->users->changeGroups($identity->userId, $add, $remove);

        // ログインに必要な条件を満たさなくなった人は、次のログインで拒否される。
        // 今のセッションも終わらせる。アカウントは無効にしない（条件を満たせばまたログインできる）。
        if (!RoleMapper::meetsRequirement($this->settings, $claims)) {
            $this->sessions->revokeAllOfUser($identity->userId, $now);
        }
    }

    /**
     * ログイン時の claim と同じ形にする。こうしておけば、権限の決め方はログイン時と同じものを使える。
     *
     * @return array<string, list<string>>
     */
    private function claimsOf(RemoteUser $state): array
    {
        $claims = [];
        foreach ([$this->settings->rolesClaimPath, $this->settings->requiredClaimPath] as $path) {
            if ($path !== '') {
                $claims[$path] = $state->roleValues;
            }
        }
        // user group 用の claim が権限用と同じなら、同じ値を使う。違うなら、IdP の group を使う。
        if ($this->settings->groupsClaimPath !== '' && !isset($claims[$this->settings->groupsClaimPath])) {
            $claims[$this->settings->groupsClaimPath] = $state->groupValues;
        }

        return $claims;
    }

    /** 無効化の割合が上限を超えていれば、その説明を返す。超えていなければ null。 */
    private static function disableRatioProblem(int $disabling, int $active, int $maxRatio): ?string
    {
        if ($disabling === 0 || $active === 0) {
            return null;
        }
        $ratio = $disabling * 100 / $active;
        if ($ratio <= $maxRatio) {
            return null;
        }

        return sprintf(
            'refusing to disable %d of %d active users (%.0f%%, limit %d%%). '
            . 'If this is expected, raise the limit for this run with --max-disable-ratio',
            $disabling,
            $active,
            $ratio,
            $maxRatio,
        );
    }
}
