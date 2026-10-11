<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Tests\User;

use LoginOIDC\Tests\Support\SettingsFactory;
use LoginOIDC\User\RoleMapper;
use PHPUnit\Framework\TestCase;

final class RoleMapperTest extends TestCase
{
    private const ROLES = [
        'rolesClaimPath' => 'resource_access.avideo.roles',
        'managedFlags' => 'isAdmin, canUpload',
        'flagMapping' => '{"admin": ["isAdmin"], "uploader": ["canUpload"], "streamer": ["canStream"]}',
    ];

    private const GROUPS = [
        'groupsClaimPath' => 'groups',
        'managedGroups' => '3, 5, 8',
        'groupMapping' => '{"staff": [3], "editors": [5, 9]}',
    ];

    /**
     * @param  list<string> $roles
     * @return array<string, mixed>
     */
    private static function claims(array $roles): array
    {
        return ['resource_access' => ['avideo' => ['roles' => $roles]]];
    }

    public function testGrantsMappedFlagsAndClearsTheRest(): void
    {
        $flags = RoleMapper::flags(SettingsFactory::make(self::ROLES), self::claims(['uploader', 'viewer']));

        self::assertSame(['isAdmin' => false, 'canUpload' => true], $flags);
    }

    public function testCombinesFlagsFromSeveralValues(): void
    {
        $flags = RoleMapper::flags(SettingsFactory::make(self::ROLES), self::claims(['uploader', 'admin']));

        self::assertSame(['isAdmin' => true, 'canUpload' => true], $flags);
    }

    public function testClearsManagedFlagsWhenClaimIsMissing(): void
    {
        self::assertSame(
            ['isAdmin' => false, 'canUpload' => false],
            RoleMapper::flags(SettingsFactory::make(self::ROLES), []),
            '権限を剥奪されたら、次のログインで落とす',
        );
    }

    public function testNeverTouchesUnmanagedFlags(): void
    {
        // streamer は canStream に対応しているが、canStream は管理対象ではない
        $flags = RoleMapper::flags(SettingsFactory::make(self::ROLES), self::claims(['streamer']));

        self::assertArrayNotHasKey('canStream', $flags);
    }

    public function testReturnsNothingWhenRolesAreNotConfigured(): void
    {
        self::assertSame([], RoleMapper::flags(SettingsFactory::make(), self::claims(['admin'])));
        self::assertSame(
            [],
            RoleMapper::flags(SettingsFactory::make(['managedFlags' => ''] + self::ROLES), self::claims(['admin'])),
        );
    }

    public function testGroupsAddsMappedAndRemovesOtherManagedGroups(): void
    {
        [$add, $remove] = RoleMapper::groups(SettingsFactory::make(self::GROUPS), ['groups' => ['editors']]);

        self::assertSame([5], $add, '9 は対応表にあるが管理対象ではないので足さない');
        self::assertSame([3, 8], $remove);
    }

    public function testGroupsDoesNothingWhenNotConfigured(): void
    {
        self::assertSame([[], []], RoleMapper::groups(SettingsFactory::make(), ['groups' => ['staff']]));
    }

    public function testRequirementIsMetWhenAnyValueMatches(): void
    {
        $settings = SettingsFactory::make([
            'requiredClaimPath' => 'resource_access.avideo.roles',
            'requiredClaimValues' => 'viewer, admin',
        ]);

        self::assertTrue(RoleMapper::meetsRequirement($settings, self::claims(['uploader', 'viewer'])));
        self::assertFalse(RoleMapper::meetsRequirement($settings, self::claims(['uploader'])));
        self::assertFalse(RoleMapper::meetsRequirement($settings, []));
    }

    public function testRequirementIsMetWhenNotConfigured(): void
    {
        self::assertTrue(RoleMapper::meetsRequirement(SettingsFactory::make(), []));
    }
}
