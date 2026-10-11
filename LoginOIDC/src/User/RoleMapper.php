<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\User;

use LoginOIDC\Claims\ClaimPath;
use LoginOIDC\Settings;
use LoginOIDC\UserFlag;

/** claim から、AVideo のフラグと user group を決める。 */
final class RoleMapper
{
    /**
     * 管理対象のフラグについて、付けるかどうかを返す。
     *
     * 管理対象でないフラグは結果に含めない（呼ぶ側はそれらに触れない）。
     * claim が無い、または空なら、管理対象のフラグはすべて false になる。
     *
     * @param  array<string, mixed> $claims
     * @return array<string, bool> UserFlag の値 → 付けるかどうか
     */
    public static function flags(Settings $settings, array $claims): array
    {
        if ($settings->rolesClaimPath === '' || $settings->managedFlags === []) {
            return [];
        }

        $granted = [];
        foreach (ClaimPath::values($claims, $settings->rolesClaimPath) as $value) {
            foreach ($settings->flagMapping[$value] ?? [] as $flag) {
                $granted[$flag->value] = true;
            }
        }

        $result = [];
        foreach ($settings->managedFlags as $flag) {
            $result[$flag->value] = isset($granted[$flag->value]);
        }

        return $result;
    }

    /**
     * 管理対象の user group について、足すものと外すものを返す。
     *
     * @param  array<string, mixed> $claims
     * @return array{list<int>, list<int>} [足す group, 外す group]
     */
    public static function groups(Settings $settings, array $claims): array
    {
        if ($settings->groupsClaimPath === '' || $settings->managedGroups === []) {
            return [[], []];
        }

        $wanted = [];
        foreach (ClaimPath::values($claims, $settings->groupsClaimPath) as $value) {
            foreach ($settings->groupMapping[$value] ?? [] as $groupId) {
                $wanted[$groupId] = true;
            }
        }

        $add = array_values(array_filter($settings->managedGroups, static fn (int $id): bool => isset($wanted[$id])));
        $remove = array_values(array_filter($settings->managedGroups, static fn (int $id): bool => !isset($wanted[$id])));

        return [$add, $remove];
    }

    /**
     * ログインに必要な条件を満たしているか。条件が設定されていなければ true。
     *
     * @param array<string, mixed> $claims
     */
    public static function meetsRequirement(Settings $settings, array $claims): bool
    {
        if ($settings->requiredClaimPath === '' || $settings->requiredClaimValues === []) {
            return true;
        }

        return array_intersect(
            ClaimPath::values($claims, $settings->requiredClaimPath),
            $settings->requiredClaimValues,
        ) !== [];
    }
}
