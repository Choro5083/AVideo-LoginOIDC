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
 * Applies changes at the provider (deleted, disabled, permissions removed, attributes changed) to AVideo.
 *
 * Only users linked to the provider are touched. No users are created (that happens on first sign-in only).
 * It goes no further than disabling a user. Videos are never touched.
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
     * @param  int|null $maxDisableRatio share of users one run may disable (%). null uses the setting
     * @throws SyncException when it cannot continue. Nothing has been changed
     */
    public function run(bool $dryRun, ?int $maxDisableRatio = null, ?int $now = null): SyncReport
    {
        $now ??= time();
        $linked = $this->identities->allLinked($this->settings->issuer);

        // Look everyone up first. If the API fails on the way, stop without changing anything.
        // This keeps a network failure from being mistaken for "the user is gone".
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
                // Only users the sync disabled are re-enabled. Users an admin disabled by hand stay disabled.
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
        // A dry run does not stop. It reports why a real run would. Its whole purpose is to show what would happen.
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

        // Someone who no longer meets the requirement to sign in is refused at the next sign-in.
        // End the current sessions too. The account stays enabled (meeting the requirement again lets them back in).
        if (!RoleMapper::meetsRequirement($this->settings, $claims)) {
            $this->sessions->revokeAllOfUser($identity->userId, $now);
        }
    }

    /**
     * Shapes the values like the claims of a sign-in, so the same rules decide the permissions.
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
        // If the claim for user groups is the one for permissions, use the same values. Otherwise use the provider's groups.
        if ($this->settings->groupsClaimPath !== '' && !isset($claims[$this->settings->groupsClaimPath])) {
            $claims[$this->settings->groupsClaimPath] = $state->groupValues;
        }

        return $claims;
    }

    /** Explains the problem when the share to disable is over the limit. null when it is not. */
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
