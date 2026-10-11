<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\User;

use LoginOIDC\Claims\ClaimPath;
use LoginOIDC\Oidc\VerifiedLogin;
use LoginOIDC\Settings;

/**
 * 検証済みの認証結果から、AVideo のユーザを決める。
 *
 * 突き合わせは (issuer, sub) だけで行う。username やメールアドレスが同じでも、
 * 既存のユーザには紐付けない。IdP 側でそれらを他人と同じにできれば、
 * 他人のアカウントに入れてしまうため。
 */
final class Provisioner
{
    public function __construct(
        private readonly Settings $settings,
        private readonly IdentityStore $identities,
        private readonly UserDirectory $users,
    ) {
    }

    /** @throws LoginDeniedException ログインを認めないとき */
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

        // 作った直後は、作成時に渡した値がそのまま入っているので更新しない。
        if (!$created) {
            $this->updateProfile($user->id, $login->claims);
        }
        $this->users->setFlags($user->id, RoleMapper::flags($this->settings, $login->claims));
        [$add, $remove] = RoleMapper::groups($this->settings, $login->claims);
        $this->users->changeGroups($user->id, $add, $remove);
        $this->identities->recordLogin($user->id);

        return new ProvisionResult($user->id, $user->username, $created);
    }

    /** @return array{int, bool} [ユーザの ID, 新しく作ったか] */
    private function createAndLink(VerifiedLogin $login): array
    {
        $claims = $login->claims;
        try {
            // ユーザの作成と対応の保存は、片方だけ残らないようにまとめて行う。
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
            // 同じ人の初回ログインが同時に2つ来ると、後の方はここに来る。
            // 先に作られた方のユーザを使う。
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
            // claim が無いときは、今の値を空で上書きしない。
            $this->settings->updateName && $name !== '' ? $name : null,
            $this->settings->updateEmail && $email !== '' ? $email : null,
            $this->settings->updateEmailVerified && is_bool($claims['email_verified'] ?? null)
                ? $claims['email_verified']
                : null,
        );
    }
}
