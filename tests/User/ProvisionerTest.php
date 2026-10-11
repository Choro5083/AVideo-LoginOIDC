<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Tests\User;

use LoginOIDC\Oidc\VerifiedLogin;
use LoginOIDC\Tests\Support\InMemoryUsers;
use LoginOIDC\Tests\Support\SettingsFactory;
use LoginOIDC\User\LoginDeniedException;
use LoginOIDC\User\Provisioner;
use PHPUnit\Framework\TestCase;

final class ProvisionerTest extends TestCase
{
    private const ISSUER = 'https://idp.example.com/realms/test';

    /** @param array<string, mixed> $claims */
    private static function login(array $claims = [], string $subject = 'sub-alice', string $issuer = self::ISSUER): VerifiedLogin
    {
        return new VerifiedLogin(
            issuer: $issuer,
            subject: $subject,
            claims: $claims + [
                'sub' => $subject,
                'preferred_username' => 'alice',
                'name' => 'Alice Example',
                'email' => 'alice@example.com',
                'email_verified' => true,
            ],
            sessionId: 'sid-1',
            idToken: 'x.y.z',
            returnTo: '/',
        );
    }

    /** @param array<string, mixed> $settings */
    private static function provisioner(InMemoryUsers $store, array $settings = []): Provisioner
    {
        return new Provisioner(SettingsFactory::make($settings), $store, $store);
    }

    public function testCreatesUserOnFirstLogin(): void
    {
        $store = new InMemoryUsers();

        $result = self::provisioner($store)->provision(self::login());

        self::assertTrue($result->created);
        self::assertSame('alice', $result->username);
        $user = $store->users[$result->userId];
        self::assertSame('Alice Example', $user['name']);
        self::assertSame('alice@example.com', $user['email']);
        self::assertTrue($user['emailVerified']);
        self::assertSame($result->userId, $store->findUserId(self::ISSUER, 'sub-alice'));
        self::assertSame([$result->userId], $store->logins);
    }

    public function testReusesLinkedUserOnLaterLogins(): void
    {
        $store = new InMemoryUsers();
        $provisioner = self::provisioner($store);
        $first = $provisioner->provision(self::login());

        $second = $provisioner->provision(self::login());

        self::assertFalse($second->created);
        self::assertSame($first->userId, $second->userId);
        self::assertCount(1, $store->users);
    }

    public function testNeverLinksToExistingUserWithSameUsernameOrEmail(): void
    {
        // 既存の「alice」は別人。IdP 側で username を合わせても、そのアカウントには入れない。
        $store = new InMemoryUsers();
        $existing = $store->add('alice');
        $store->users[$existing]['email'] = 'alice@example.com';

        $result = self::provisioner($store)->provision(self::login());

        self::assertNotSame($existing, $result->userId);
        self::assertSame('alice2', $result->username);
        self::assertTrue($result->created);
    }

    public function testSameSubjectFromAnotherIssuerIsADifferentUser(): void
    {
        $store = new InMemoryUsers();
        $provisioner = self::provisioner($store);
        $first = $provisioner->provision(self::login());

        $second = $provisioner->provision(self::login(issuer: 'https://other-idp.example.com'));

        self::assertNotSame($first->userId, $second->userId);
    }

    public function testKeepsUsernameWhenRenamedAtTheProvider(): void
    {
        $store = new InMemoryUsers();
        $provisioner = self::provisioner($store);
        $first = $provisioner->provision(self::login());

        $second = $provisioner->provision(self::login(['preferred_username' => 'alice-renamed', 'name' => 'Alice Renamed']));

        self::assertSame('alice', $second->username, 'username は作成後に変えない');
        self::assertSame('Alice Renamed', $store->users[$first->userId]['name'], '名前は更新する');
    }

    public function testUpdatesOnlyTheProfileFieldsEnabledInSettings(): void
    {
        $store = new InMemoryUsers();
        $provisioner = self::provisioner($store, ['updateName' => false, 'updateEmailVerified' => false]);
        $id = $provisioner->provision(self::login())->userId;

        $provisioner->provision(self::login(['name' => 'Changed', 'email' => 'new@example.com', 'email_verified' => false]));

        self::assertSame('Alice Example', $store->users[$id]['name']);
        self::assertSame('new@example.com', $store->users[$id]['email']);
        self::assertTrue($store->users[$id]['emailVerified']);
    }

    public function testDoesNotBlankProfileWhenClaimsAreMissing(): void
    {
        $store = new InMemoryUsers();
        $provisioner = self::provisioner($store);
        $id = $provisioner->provision(self::login())->userId;

        $provisioner->provision(self::login(['name' => '', 'email' => '', 'email_verified' => null]));

        self::assertSame('Alice Example', $store->users[$id]['name']);
        self::assertSame('alice@example.com', $store->users[$id]['email']);
        self::assertTrue($store->users[$id]['emailVerified']);
    }

    public function testAppliesAndRevokesFlagsOnEachLogin(): void
    {
        $store = new InMemoryUsers();
        $provisioner = self::provisioner($store, [
            'rolesClaimPath' => 'roles',
            'managedFlags' => 'isAdmin, canUpload',
            'flagMapping' => '{"admin": ["isAdmin"], "uploader": ["canUpload"]}',
        ]);

        $id = $provisioner->provision(self::login(['roles' => ['admin', 'uploader']]))->userId;
        self::assertSame(['isAdmin' => true, 'canUpload' => true], $store->users[$id]['flags']);

        $provisioner->provision(self::login(['roles' => ['uploader']]));
        self::assertSame(['isAdmin' => false, 'canUpload' => true], $store->users[$id]['flags'], '剥奪された権限は落とす');
    }

    public function testChangesOnlyManagedGroups(): void
    {
        $store = new InMemoryUsers();
        $provisioner = self::provisioner($store, [
            'groupsClaimPath' => 'groups',
            'managedGroups' => '3, 5',
            'groupMapping' => '{"staff": [3], "editors": [5]}',
        ]);
        $id = $provisioner->provision(self::login(['groups' => ['staff']]))->userId;
        $store->users[$id]['groups'][] = 7; // 管理者が手で足した、管理対象でない group

        $provisioner->provision(self::login(['groups' => ['editors']]));

        self::assertSame([5, 7], $store->users[$id]['groups']);
    }

    public function testDeniesWhenRequiredClaimIsMissingAndCreatesNothing(): void
    {
        $store = new InMemoryUsers();
        $provisioner = self::provisioner($store, ['requiredClaimPath' => 'roles', 'requiredClaimValues' => 'viewer']);

        try {
            $provisioner->provision(self::login(['roles' => ['other']]));
            self::fail('条件を満たさないのにログインできた');
        } catch (LoginDeniedException $e) {
            self::assertStringContainsString('required', $e->getMessage());
        }
        self::assertSame([], $store->users);
        self::assertSame([], $store->links);
    }

    public function testDeniesUnlinkedUserWhenCreationIsDisabled(): void
    {
        $store = new InMemoryUsers();

        $this->expectException(LoginDeniedException::class);
        $this->expectExceptionMessage('user creation is disabled');

        self::provisioner($store, ['createUsers' => false])->provision(self::login());
    }

    public function testAllowsPreLinkedUserWhenCreationIsDisabled(): void
    {
        $store = new InMemoryUsers();
        $id = $store->add('operator');
        $store->link($id, self::ISSUER, 'sub-alice');

        $result = self::provisioner($store, ['createUsers' => false])->provision(self::login());

        self::assertSame($id, $result->userId);
        self::assertSame('operator', $result->username);
    }

    public function testDeniesDisabledUserWithoutChangingIt(): void
    {
        $store = new InMemoryUsers();
        $provisioner = self::provisioner($store, [
            'rolesClaimPath' => 'roles', 'managedFlags' => 'isAdmin', 'flagMapping' => '{"admin": ["isAdmin"]}',
        ]);
        $id = $provisioner->provision(self::login())->userId;
        $store->users[$id]['active'] = false;
        $store->logins = [];

        try {
            $provisioner->provision(self::login(['roles' => ['admin'], 'name' => 'Changed']));
            self::fail('無効のユーザがログインできた');
        } catch (LoginDeniedException $e) {
            self::assertStringContainsString('disabled', $e->getMessage());
        }
        self::assertSame('Alice Example', $store->users[$id]['name'], '無効のユーザの情報は更新しない');
        self::assertSame(['isAdmin' => false], $store->users[$id]['flags']);
        self::assertSame([], $store->logins);
    }

    public function testConcurrentFirstLoginsEndUpWithOneUser(): void
    {
        // ユーザを作って対応を保存する直前に、同じ人の別のリクエストが先に完了した場合
        $store = new InMemoryUsers();
        $winner = null;
        $store->beforeLink = static function () use ($store, &$winner): void {
            $winner = $store->add('alice-from-other-request');
            $store->links[self::ISSUER . "\nsub-alice"] = $winner;
        };

        $result = self::provisioner($store)->provision(self::login());

        self::assertSame($winner, $result->userId, '先に作られた方のユーザを使う');
        self::assertFalse($result->created);
    }
}
