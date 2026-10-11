<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\User;

use LoginOIDC\Claims\ClaimPath;
use LoginOIDC\Settings;
use LoginOIDC\UserFlag;

/** Decides AVideo's flags and user groups from the claims. */
final class RoleMapper
{
    /**
     * Says, for each managed flag, whether to grant it.
     *
     * Flags that are not managed are left out of the result (the caller does not touch them).
     * When the claim is missing or empty, every managed flag is false.
     *
     * @param  array<string, mixed> $claims
     * @return array<string, bool> UserFlag value → whether to grant it
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
     * Says which managed user groups to add and which to remove.
     *
     * @param  array<string, mixed> $claims
     * @return array{list<int>, list<int>} [groups to add, groups to remove]
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
     * Whether the requirement to sign in is met. true when none is configured.
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
