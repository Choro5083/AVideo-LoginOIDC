<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Tests\Sync;

use LoginOIDC\Http\HttpResponse;
use LoginOIDC\Oidc\SecretBasicAuthenticator;
use LoginOIDC\Sync\KeycloakDirectory;
use LoginOIDC\Sync\SyncException;
use LoginOIDC\SyncRoleSource;
use LoginOIDC\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KeycloakDirectoryTest extends TestCase
{
    private const TOKEN = 'https://idp.example.com/realms/test/protocol/openid-connect/token';
    private const ADMIN = 'https://idp.example.com/admin/realms/test';
    private const USER = ['id' => 'u1', 'enabled' => true, 'firstName' => 'Bob', 'lastName' => 'Uploader',
                          'email' => 'bob@example.com', 'emailVerified' => true];

    /** @param array<string, HttpResponse> $responses */
    private static function directory(
        array $responses,
        SyncRoleSource $source = SyncRoleSource::Groups,
        string $clientId = '',
    ): array {
        $http = new FakeHttpClient($responses + [self::TOKEN => FakeHttpClient::json(['access_token' => 'token-1'])]);

        return [
            new KeycloakDirectory($http, new SecretBasicAuthenticator('avideo-sync', 'secret'), self::TOKEN, self::ADMIN, $source, $clientId),
            $http,
        ];
    }

    private static function list(array $items, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, json_encode($items, JSON_THROW_ON_ERROR));
    }

    public function testDerivesAdminUrlFromIssuer(): void
    {
        self::assertSame(self::ADMIN, KeycloakDirectory::adminUrlFromIssuer('https://idp.example.com/realms/test'));
        self::assertSame(
            'https://idp.example.com/auth/admin/realms/test',
            KeycloakDirectory::adminUrlFromIssuer('https://idp.example.com/auth/realms/test'),
            '古い Keycloak の /auth 付きの URL',
        );
        self::assertSame('', KeycloakDirectory::adminUrlFromIssuer('https://accounts.example.com'));
    }

    public function testReadsUserAndGroups(): void
    {
        [$directory, $http] = self::directory([
            self::ADMIN . '/users/u1' => FakeHttpClient::json(self::USER),
            self::ADMIN . '/users/u1/groups?briefRepresentation=true&max=1000' => self::list([
                ['id' => 'g1', 'name' => 'uploaders', 'path' => '/staff/uploaders'],
            ]),
        ]);

        $user = $directory->lookup('u1');

        self::assertNotNull($user);
        self::assertTrue($user->enabled);
        self::assertSame('Bob Uploader', $user->name);
        self::assertSame('bob@example.com', $user->email);
        self::assertTrue($user->emailVerified);
        self::assertSame(['uploaders', '/staff/uploaders'], $user->roleValues, '名前とパスの両方');
        self::assertSame('Bearer token-1', $http->requests[1]['headers']['Authorization']);
    }

    public function testReusesTheTokenAcrossLookups(): void
    {
        [$directory, $http] = self::directory([
            self::ADMIN . '/users/u1' => FakeHttpClient::json(self::USER),
            self::ADMIN . '/users/u1/groups?briefRepresentation=true&max=1000' => self::list([]),
        ]);

        $directory->lookup('u1');
        $directory->lookup('u1');

        self::assertSame(1, $http->countRequestsTo(self::TOKEN));
    }

    public function testReturnsNullOnlyFor404(): void
    {
        [$directory] = self::directory([self::ADMIN . '/users/gone' => FakeHttpClient::json(['error' => 'User not found'], 404)]);

        self::assertNull($directory->lookup('gone'));
    }

    /** @return iterable<string, array{int}> */
    public static function failureStatuses(): iterable
    {
        yield '権限が無い' => [403];
        yield 'トークンが無効' => [401];
        yield 'サーバのエラー' => [500];
        yield '一時的に使えない' => [503];
    }

    #[DataProvider('failureStatuses')]
    public function testFailureIsNeverMistakenForAMissingUser(int $status): void
    {
        [$directory] = self::directory([self::ADMIN . '/users/u1' => FakeHttpClient::json(['error' => 'x'], $status)]);

        $this->expectException(SyncException::class);

        $directory->lookup('u1');
    }

    public function testUnreachableApiIsAFailure(): void
    {
        // 応答を用意していない URL は、接続できないのと同じ扱い（FakeHttpClient が HttpException を投げる）
        [$directory] = self::directory([]);

        $this->expectException(SyncException::class);
        $this->expectExceptionMessage('Admin API request failed');

        $directory->lookup('u1');
    }

    public function testTokenFailureIsReported(): void
    {
        [$directory] = self::directory([self::TOKEN => FakeHttpClient::json(['error' => 'unauthorized_client'], 401)]);

        $this->expectException(SyncException::class);
        $this->expectExceptionMessage('unauthorized_client');

        $directory->lookup('u1');
    }

    public function testReadsEffectiveClientRoles(): void
    {
        [$directory] = self::directory([
            self::ADMIN . '/users/u1' => FakeHttpClient::json(self::USER),
            self::ADMIN . '/users/u1/groups?briefRepresentation=true&max=1000' => self::list([['name' => 'g', 'path' => '/g']]),
            self::ADMIN . '/clients?clientId=avideo' => self::list([['id' => 'client-uuid', 'clientId' => 'avideo']]),
            self::ADMIN . '/users/u1/role-mappings/clients/client-uuid/composite' => self::list([['name' => 'uploader'], ['name' => 'viewer']]),
        ], SyncRoleSource::ClientRoles, 'avideo');

        $user = $directory->lookup('u1');

        self::assertNotNull($user);
        self::assertSame(['uploader', 'viewer'], $user->roleValues);
        self::assertSame(['g', '/g'], $user->groupValues);
    }

    public function testExplainsMissingPermissionForClientRoles(): void
    {
        // view-clients が無いと、client の一覧が空で返る
        [$directory] = self::directory([
            self::ADMIN . '/users/u1' => FakeHttpClient::json(self::USER),
            self::ADMIN . '/users/u1/groups?briefRepresentation=true&max=1000' => self::list([]),
            self::ADMIN . '/clients?clientId=avideo' => self::list([]),
        ], SyncRoleSource::ClientRoles, 'avideo');

        $this->expectException(SyncException::class);
        $this->expectExceptionMessage('view-clients');

        $directory->lookup('u1');
    }

    public function testReadsRealmRoles(): void
    {
        [$directory] = self::directory([
            self::ADMIN . '/users/u1' => FakeHttpClient::json(self::USER),
            self::ADMIN . '/users/u1/groups?briefRepresentation=true&max=1000' => self::list([]),
            self::ADMIN . '/users/u1/role-mappings/realm/composite' => self::list([['name' => 'admin']]),
        ], SyncRoleSource::RealmRoles);

        self::assertSame(['admin'], $directory->lookup('u1')?->roleValues);
    }
}
