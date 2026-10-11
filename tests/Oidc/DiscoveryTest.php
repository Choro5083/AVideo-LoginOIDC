<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Tests\Oidc;

use LoginOIDC\Http\HttpResponse;
use LoginOIDC\Oidc\Discovery;
use LoginOIDC\Oidc\OidcException;
use LoginOIDC\Tests\Support\ArrayCache;
use LoginOIDC\Tests\Support\FakeHttpClient;
use LoginOIDC\Tests\Support\TestIdp;
use PHPUnit\Framework\TestCase;

final class DiscoveryTest extends TestCase
{
    private const URL = TestIdp::ISSUER . '/.well-known/openid-configuration';

    /** @param array<string, mixed> $overrides */
    private static function http(array $overrides = []): FakeHttpClient
    {
        return new FakeHttpClient([self::URL => FakeHttpClient::json(array_merge(TestIdp::discoveryDocument(), $overrides))]);
    }

    public function testFetchesAndCachesMetadata(): void
    {
        $http = self::http();
        $discovery = new Discovery($http, new ArrayCache());

        $first = $discovery->fetch(TestIdp::ISSUER);
        $second = $discovery->fetch(TestIdp::ISSUER);

        self::assertSame(TestIdp::ISSUER . '/token', $first->tokenEndpoint);
        self::assertSame(['client_secret_basic', 'client_secret_post'], $first->tokenEndpointAuthMethods);
        self::assertEquals($first, $second);
        self::assertSame(1, $http->countRequestsTo(self::URL), '2回目はキャッシュから返す');
    }

    public function testDefaultsAuthMethodToSecretBasic(): void
    {
        $metadata = (new Discovery(self::http(['token_endpoint_auth_methods_supported' => null]), new ArrayCache()))
            ->fetch(TestIdp::ISSUER);

        self::assertSame(['client_secret_basic'], $metadata->tokenEndpointAuthMethods);
    }

    public function testRejectsIssuerMismatch(): void
    {
        $this->expectException(OidcException::class);
        $this->expectExceptionMessage('does not match the configured issuer');

        (new Discovery(self::http(['issuer' => 'https://evil.example.com']), new ArrayCache()))->fetch(TestIdp::ISSUER);
    }

    public function testRejectsMissingEndpoint(): void
    {
        $this->expectException(OidcException::class);
        $this->expectExceptionMessage('token_endpoint');

        (new Discovery(self::http(['token_endpoint' => null]), new ArrayCache()))->fetch(TestIdp::ISSUER);
    }

    public function testRejectsHttpIssuerByDefault(): void
    {
        $http = new FakeHttpClient();

        try {
            (new Discovery($http, new ArrayCache()))->fetch('http://idp.example.com');
            self::fail('http の issuer を受け入れた');
        } catch (OidcException $e) {
            self::assertStringContainsString('https', $e->getMessage());
        }
        self::assertSame([], $http->requests, '通信する前に弾く');
    }

    public function testRejectsHttpEndpointInHttpsDocument(): void
    {
        $this->expectException(OidcException::class);
        $this->expectExceptionMessage('token_endpoint');

        (new Discovery(self::http(['token_endpoint' => 'http://idp.example.com/token']), new ArrayCache()))
            ->fetch(TestIdp::ISSUER);
    }

    public function testAllowsHttpWhenExplicitlyEnabled(): void
    {
        $issuer = 'http://localhost:8081/realms/test';
        $document = array_map(
            static fn (mixed $v): mixed => is_string($v) ? str_replace(TestIdp::ISSUER, $issuer, $v) : $v,
            TestIdp::discoveryDocument(),
        );
        $http = new FakeHttpClient([$issuer . '/.well-known/openid-configuration' => FakeHttpClient::json($document)]);

        $metadata = (new Discovery($http, new ArrayCache(), allowInsecureHttp: true))->fetch($issuer);

        self::assertSame($issuer, $metadata->issuer);
    }

    public function testReportsHttpErrorStatus(): void
    {
        $this->expectException(OidcException::class);
        $this->expectExceptionMessage('HTTP 404');

        (new Discovery(new FakeHttpClient([self::URL => new HttpResponse(404, 'Not Found')]), new ArrayCache()))
            ->fetch(TestIdp::ISSUER);
    }
}
