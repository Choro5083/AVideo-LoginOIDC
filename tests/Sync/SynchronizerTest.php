<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Tests\Sync;

use LoginOIDC\Sync\SyncException;
use LoginOIDC\Sync\Synchronizer;
use LoginOIDC\Tests\Support\FakeRemoteDirectory;
use LoginOIDC\Tests\Support\InMemoryUsers;
use LoginOIDC\Tests\Support\SettingsFactory;
use PHPUnit\Framework\TestCase;

final class SynchronizerTest extends TestCase
{
    private const ISSUER = 'https://idp.example.com/realms/test';
    private const NOW = 5000;

    private const ROLES = [
        'rolesClaimPath' => 'groups',
        'managedFlags' => 'isAdmin, canUpload',
        'flagMapping' => '{"admins": ["isAdmin"], "uploaders": ["canUpload"]}',
        'syncMaxDisableRatio' => 100,
    ];

    private InMemoryUsers $store;
    private FakeRemoteDirectory $remote;

    protected function setUp(): void
    {
        $this->store = new InMemoryUsers();
        $this->remote = new FakeRemoteDirectory();
    }

    /** Creates a linked user that exists both at the provider and in AVideo. */
    private function linked(string $username, array $roles = [], bool $active = true): int
    {
        $id = $this->store->add($username, $active);
        $this->store->link($id, self::ISSUER, "sub-{$username}");
        $this->remote->put("sub-{$username}", $roles);

        return $id;
    }

    /** @param array<string, mixed> $settings */
    private function synchronizer(array $settings = []): Synchronizer
    {
        return new Synchronizer(
            SettingsFactory::make($settings + self::ROLES + ['issuer' => self::ISSUER]),
            $this->store,
            $this->store,
            $this->store,
            $this->remote,
        );
    }

    public function testDisablesUsersDeletedOrDisabledAtTheProvider(): void
    {
        $deleted = $this->linked('deleted');
        $disabled = $this->linked('disabled');
        $kept = $this->linked('kept');
        unset($this->remote->users['sub-deleted']);
        $this->remote->put('sub-disabled', enabled: false);

        $report = $this->synchronizer()->run(dryRun: false, now: self::NOW);

        self::assertFalse($this->store->users[$deleted]['active']);
        self::assertFalse($this->store->users[$disabled]['active']);
        self::assertTrue($this->store->users[$kept]['active']);
        self::assertSame([$deleted, $disabled], $this->store->disabledBySync);
        self::assertSame([$deleted => self::NOW, $disabled => self::NOW], $this->store->revoked, 'the sessions are ended too');
        self::assertSame(['deleted (deleted at the provider)', 'disabled (disabled at the provider)'], $report->disabled);
        self::assertArrayHasKey($deleted, $this->store->users, 'never deleted');
    }

    public function testReEnablesOnlyUsersItDisabledItself(): void
    {
        $bySync = $this->linked('by-sync', active: false);
        $byAdmin = $this->linked('by-admin', active: false);
        $this->store->setDisabledBySync($bySync, true);

        $report = $this->synchronizer()->run(dryRun: false, now: self::NOW);

        self::assertTrue($this->store->users[$bySync]['active']);
        self::assertFalse($this->store->users[$byAdmin]['active'], 'a user an admin disabled by hand stays disabled');
        self::assertSame([], $this->store->disabledBySync);
        self::assertSame(['by-sync'], $report->enabled);
    }

    public function testAppliesRoleChanges(): void
    {
        $id = $this->linked('bob', ['uploaders']);
        $this->store->users[$id]['flags'] = ['isAdmin' => true, 'canUpload' => false];

        $this->synchronizer()->run(dryRun: false, now: self::NOW);

        self::assertSame(['isAdmin' => false, 'canUpload' => true], $this->store->users[$id]['flags']);
    }

    public function testUpdatesProfileWithoutBlankingIt(): void
    {
        $id = $this->linked('bob');
        $this->store->users[$id]['name'] = 'Old Name';
        $this->store->users[$id]['email'] = 'old@example.com';
        $this->remote->put('sub-bob', name: 'New Name', email: '');

        $this->synchronizer()->run(dryRun: false, now: self::NOW);

        self::assertSame('New Name', $this->store->users[$id]['name']);
        self::assertSame('old@example.com', $this->store->users[$id]['email'], 'not overwritten when the provider has it empty');
    }

    public function testNeverCreatesUsersOrTouchesUnlinkedOnes(): void
    {
        $native = $this->store->add('admin');
        $this->remote->put('sub-someone-new', ['admins']);

        $report = $this->synchronizer()->run(dryRun: false, now: self::NOW);

        self::assertCount(1, $this->store->users, 'a user that exists only at the provider is not created');
        self::assertTrue($this->store->users[$native]['active']);
        self::assertSame([], $this->store->users[$native]['flags']);
        self::assertSame(0, $report->checked);
    }

    public function testIgnoresUsersLinkedToAnotherIssuer(): void
    {
        $id = $this->store->add('other');
        $this->store->link($id, 'https://other-idp.example.com', 'sub-other');

        $this->synchronizer()->run(dryRun: false, now: self::NOW);

        self::assertTrue($this->store->users[$id]['active'], 'a user of another issuer is not taken for gone');
    }

    public function testChangesNothingWhenAnyLookupFails(): void
    {
        $gone = $this->linked('gone');
        unset($this->remote->users['sub-gone']);
        $this->linked('flaky');
        $this->remote->failing[] = 'sub-flaky';

        try {
            $this->synchronizer()->run(dryRun: false, now: self::NOW);
            self::fail('the sync went on although the API failed');
        } catch (SyncException) {
            // expected
        }

        self::assertTrue($this->store->users[$gone]['active'], 'even a user that is certainly gone is not disabled in this run');
        self::assertSame([], $this->store->revoked);
    }

    public function testRefusesToDisableMoreThanTheConfiguredRatio(): void
    {
        // nearly everyone looks gone, as when the settings point at another realm
        $ids = array_map(fn (int $n): int => $this->linked("user{$n}"), range(1, 10));
        foreach (range(1, 3) as $n) {
            unset($this->remote->users["sub-user{$n}"]);
        }

        try {
            $this->synchronizer(['syncMaxDisableRatio' => 20])->run(dryRun: false, now: self::NOW);
            self::fail('disabled more users than the limit allows');
        } catch (SyncException $e) {
            self::assertStringContainsString('3 of 10', $e->getMessage());
        }
        foreach ($ids as $id) {
            self::assertTrue($this->store->users[$id]['active']);
        }
    }

    public function testRatioCanBeRaisedForOneRun(): void
    {
        $id = $this->linked('user1');
        $this->linked('user2');
        unset($this->remote->users['sub-user1']);

        $this->synchronizer(['syncMaxDisableRatio' => 20])->run(dryRun: false, maxDisableRatio: 50, now: self::NOW);

        self::assertFalse($this->store->users[$id]['active']);
    }

    public function testRatioAtTheLimitIsAllowed(): void
    {
        $ids = array_map(fn (int $n): int => $this->linked("user{$n}"), range(1, 5));
        unset($this->remote->users['sub-user1']);

        $this->synchronizer(['syncMaxDisableRatio' => 20])->run(dryRun: false, now: self::NOW);

        self::assertFalse($this->store->users[$ids[0]]['active'], '1 of 5 is exactly 20%');
    }

    public function testDryRunReportsButChangesNothing(): void
    {
        $gone = $this->linked('gone');
        $bob = $this->linked('bob', ['admins']);
        unset($this->remote->users['sub-gone']);
        $before = $this->store->users;

        $report = $this->synchronizer()->run(dryRun: true, now: self::NOW);

        self::assertSame($before, $this->store->users);
        self::assertSame([], $this->store->revoked);
        self::assertTrue($report->dryRun);
        self::assertSame(['gone (deleted at the provider)'], $report->disabled);
        self::assertSame(1, $report->refreshed);
        self::assertStringStartsWith('[dry-run] ', $report->summary());
        self::assertTrue($this->store->users[$gone]['active'] && $this->store->users[$bob]['active']);
    }

    public function testDryRunShowsThePlanEvenWhenARealRunWouldRefuse(): void
    {
        $this->linked('user1');
        $this->linked('user2');
        unset($this->remote->users['sub-user1']);

        $report = $this->synchronizer(['syncMaxDisableRatio' => 20])->run(dryRun: true, now: self::NOW);

        self::assertSame(['user1 (deleted at the provider)'], $report->disabled);
        self::assertNotNull($report->refusal);
        self::assertStringContainsString('1 of 2', $report->refusal);
    }

    public function testRevokesSessionsWhenRequiredValueIsLost(): void
    {
        $settings = ['requiredClaimPath' => 'groups', 'requiredClaimValues' => 'viewers'];
        $stays = $this->linked('stays', ['viewers']);
        $loses = $this->linked('loses', ['uploaders']);

        $report = $this->synchronizer($settings)->run(dryRun: false, now: self::NOW);

        self::assertSame([$loses => self::NOW], $this->store->revoked);
        self::assertTrue($this->store->users[$loses]['active'], 'the account stays enabled (meeting the requirement again lets them back in)');
        self::assertSame(['loses'], $report->sessionsRevoked);
        self::assertTrue($this->store->users[$stays]['active']);
    }
}
