<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Tests\User;

use LoginOIDC\User\UsernamePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UsernamePolicyTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function rawNames(): iterable
    {
        yield 'そのまま使える' => ['alice', 'alice'];
        yield 'メールアドレス' => ['alice@example.com', 'alice@example.com'];
        yield '空白と記号を除く（マークアップは残らない）' => ['<b>al ice</b>', 'baliceb'];
        yield '引用符を除く' => ["o'brien\"", 'obrien'];
        yield '日本語は残らない' => ['山田 taro', 'taro'];
        yield '前後の記号を落とす' => ['.-alice-.', 'alice'];
        yield '空なら既定の名前' => ['', 'user'];
        yield '使える文字が無ければ既定の名前' => ['山田', 'user'];
    }

    #[DataProvider('rawNames')]
    public function testSanitize(string $raw, string $expected): void
    {
        self::assertSame($expected, UsernamePolicy::sanitize($raw));
    }

    public function testSanitizeTruncatesToColumnLength(): void
    {
        $sanitized = UsernamePolicy::sanitize(str_repeat('a', 100));

        self::assertSame(UsernamePolicy::MAX_LENGTH, strlen($sanitized));
    }

    public function testUniqueReturnsBaseWhenFree(): void
    {
        self::assertSame('alice', UsernamePolicy::unique('alice', static fn (string $name): bool => false));
    }

    public function testUniqueAppendsNumberWhenTaken(): void
    {
        $taken = ['alice', 'alice2'];

        self::assertSame('alice3', UsernamePolicy::unique('alice', static fn (string $n): bool => in_array($n, $taken, true)));
    }

    public function testUniqueKeepsLengthLimitWhenAppending(): void
    {
        $base = str_repeat('a', UsernamePolicy::MAX_LENGTH);

        $unique = UsernamePolicy::unique($base, static fn (string $n): bool => $n === $base);

        self::assertSame(UsernamePolicy::MAX_LENGTH, strlen($unique));
        self::assertStringEndsWith('2', $unique);
    }
}
