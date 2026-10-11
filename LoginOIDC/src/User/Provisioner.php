<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\User;

use LoginOIDC\Claims\ClaimPath;
use LoginOIDC\Oidc\VerifiedLogin;
use LoginOIDC\Settings;

/**
 * Decides which AVideo user a verified sign-in belongs to.
 *
 * Matching uses (issuer, sub) only. A user with the same username or e-mail address
 * is never linked. If someone could make those equal to another person's at the
 * provider, they would get into that person's account.
 */
final class Provisioner
{
    public function __construct(
        private readonly Settings $settings,
        private readonly IdentityStore $identities,
        private readonly UserDirectory $users,
    ) {
    }

    /** @throws LoginDeniedException when the sign-in is not allowed */
    public function provision(VerifiedLogin $login): ProvisionResult
    {
        if (!RoleMapper::meetsRequirement($this->settings, $login->claims)) {
            throw new LoginDeniedException(
                "sub {$login->subject} does not have a required value in claim {$this->settings->requiredClaimPath}"
            );
        }

        $userId = $this->identities->findUserId($login->issuer, $login->subject);
        $created = false;
        if ($userId === null) {
            if (!$this->settings->createUsers) {
                throw new LoginDeniedException("sub {$login->subject} is not linked to a user, and user creation is disabled");
            }
            [$userId, $created] = $this->createAndLink($login);
        }

        $user = $this->users->find($userId)
            ?? throw new LoginDeniedException("user {$userId} linked to sub {$login->subject} no longer exists");
        if (!$user->active) {
            throw new LoginDeniedException("user {$user->username} (id {$user->id}) is disabled");
        }

        // A user created just now already holds the values passed at creation, so skip the update.
        if (!$created) {
            $this->updateProfile($user->id, $login->claims);
        }
        $this->users->setFlags($user->id, RoleMapper::flags($this->settings, $login->claims));
        [$add, $remove] = RoleMapper::groups($this->settings, $login->claims);
        $this->users->changeGroups($user->id, $add, $remove);
        $this->identities->recordLogin($user->id);

        return new ProvisionResult($user->id, $user->username, $created);
    }

    /** @return array{int, bool} [user ID, whether it was created] */
    private function createAndLink(VerifiedLogin $login): array
    {
        $claims = $login->claims;
        try {
            // Create the user and store the link together, so that never only one of them remains.
            $userId = $this->identities->transactional(function () use ($login, $claims): int {
                $userId = $this->users->create(new NewUser(
                    username: UsernamePolicy::unique(
                        ClaimPath::string($claims, $this->settings->usernameClaim),
                        $this->users->usernameExists(...),
                    ),
                    name: ClaimPath::string($claims, $this->settings->nameClaim),
                    email: ClaimPath::string($claims, $this->settings->emailClaim),
                    emailVerified: ($claims['email_verified'] ?? null) === true,
                ));
                $this->identities->link($userId, $login->issuer, $login->subject);

                return $userId;
            });

            return [$userId, true];
        } catch (DuplicateIdentityException $e) {
            // When two first sign-ins of the same person arrive at once, the later one ends up here.
            // Use the user the earlier one created.
            $existing = $this->identities->findUserId($login->issuer, $login->subject)
                ?? throw new LoginDeniedException("cannot link sub {$login->subject}: {$e->getMessage()}", 0, $e);

            return [$existing, false];
        }
    }

    /** @param array<string, mixed> $claims */
    private function updateProfile(int $userId, array $claims): void
    {
        $name = ClaimPath::string($claims, $this->settings->nameClaim);
        $email = ClaimPath::string($claims, $this->settings->emailClaim);

        $this->users->updateProfile(
            $userId,
            // When a claim is missing, do not blank the current value.
            $this->settings->updateName && $name !== '' ? $name : null,
            $this->settings->updateEmail && $email !== '' ? $email : null,
            $this->settings->updateEmailVerified && is_bool($claims['email_verified'] ?? null)
                ? $claims['email_verified']
                : null,
        );
    }
}
