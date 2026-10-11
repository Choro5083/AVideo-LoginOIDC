<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Tests\Claims;

use LoginOIDC\Claims\ClaimPath;
use PHPUnit\Framework\TestCase;

final class ClaimPathTest extends TestCase
{
    private const CLAIMS = [
        'groups' => ['staff', 'editors'],
        'role' => 'admin',
        'empty' => '',
        'number' => 42,
        'mixed' => ['a', 1, null, '', 'b'],
        'resource_access' => [
            'avideo' => ['roles' => ['admin', 'uploader']],
            'my.client' => ['roles' => ['viewer']],
        ],
        'https://example.com/roles' => ['namespaced'],
    ];

    public function testReadsNestedArray(): void
    {
        self::assertSame(['admin', 'uploader'], ClaimPath::values(self::CLAIMS, 'resource_access.avideo.roles'));
    }

    public function testReadsTopLevelArrayAndString(): void
    {
        self::assertSame(['staff', 'editors'], ClaimPath::values(self::CLAIMS, 'groups'));
        self::assertSame(['admin'], ClaimPath::values(self::CLAIMS, 'role'), 'a string counts as a one-element list');
    }

    public function testResolvesKeysContainingDots(): void
    {
        self::assertSame(['viewer'], ClaimPath::values(self::CLAIMS, 'resource_access.my.client.roles'));
        self::assertSame(['namespaced'], ClaimPath::values(self::CLAIMS, 'https://example.com/roles'));
    }

    public function testReturnsEmptyForMissingOrUnusableValues(): void
    {
        self::assertSame([], ClaimPath::values(self::CLAIMS, 'resource_access.other.roles'));
        self::assertSame([], ClaimPath::values(self::CLAIMS, 'nope'));
        self::assertSame([], ClaimPath::values(self::CLAIMS, ''));
        self::assertSame([], ClaimPath::values(self::CLAIMS, 'empty'));
        self::assertSame([], ClaimPath::values(self::CLAIMS, 'number'));
        self::assertSame([], ClaimPath::values(self::CLAIMS, 'role.deeper'), 'nothing can be followed beyond a string');
    }

    public function testKeepsOnlyNonEmptyStringsFromArrays(): void
    {
        self::assertSame(['a', 'b'], ClaimPath::values(self::CLAIMS, 'mixed'));
    }

    public function testStringReturnsOnlyStrings(): void
    {
        self::assertSame('admin', ClaimPath::string(self::CLAIMS, 'role'));
        self::assertSame('', ClaimPath::string(self::CLAIMS, 'groups'));
        self::assertSame('', ClaimPath::string(self::CLAIMS, 'missing'));
    }
}
