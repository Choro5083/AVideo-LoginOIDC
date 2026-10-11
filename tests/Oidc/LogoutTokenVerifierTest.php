<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Tests\Oidc;

use LoginOIDC\Oidc\Discovery;
use LoginOIDC\Oidc\JwksProvider;
use LoginOIDC\Oidc\JwtVerifier;
use LoginOIDC\Oidc\LogoutTokenVerifier;
use LoginOIDC\Oidc\OidcException;
use LoginOIDC\Tests\Support\ArrayCache;
use LoginOIDC\Tests\Support\FakeHttpClient;
use LoginOIDC\Tests\Support\TestIdp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LogoutTokenVerifierTest extends TestCase
{
    private static TestIdp $idp;

    public static function setUpBeforeClass(): void
    {
        self::$idp = new TestIdp();
    }

    private static function verifier(): LogoutTokenVerifier
    {
        $http = new FakeHttpClient([
            TestIdp::ISSUER . '/.well-known/openid-configuration' => FakeHttpClient::json(TestIdp::discoveryDocument()),
            TestIdp::ISSUER . '/certs' => FakeHttpClient::json(self::$idp->jwks()),
        ]);
        $cache = new ArrayCache();

        return new LogoutTokenVerifier(
            new Discovery($http, $cache),
            new JwtVerifier(new JwksProvider($http, $cache), 60),
            TestIdp::ISSUER,
            TestIdp::CLIENT_ID,
        );
    }

    public function testAcceptsValidToken(): void
    {
        $notice = self::verifier()->verify(self::$idp->logoutToken(), TestIdp::NOW);

        self::assertSame(TestIdp::ISSUER, $notice->issuer);
        self::assertSame('user-1', $notice->subject);
        self::assertSame('session-1', $notice->sessionId);
    }

    public function testAcceptsTokenWithOnlySidOrOnlySub(): void
    {
        $onlySid = self::verifier()->verify(self::$idp->logoutToken(['sub' => null]), TestIdp::NOW);
        $onlySub = self::verifier()->verify(self::$idp->logoutToken(['sid' => null]), TestIdp::NOW);

        self::assertSame('', $onlySid->subject);
        self::assertSame('session-1', $onlySid->sessionId);
        self::assertSame('user-1', $onlySub->subject);
        self::assertSame('', $onlySub->sessionId);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidTokens(): iterable
    {
        yield 'iss が違う' => [['iss' => 'https://evil.example.com'], 'iss'];
        yield 'aud が違う' => [['aud' => 'other-client'], 'aud'];
        yield 'iat が無い' => [['iat' => null], 'iat'];
        yield 'events が無い' => [['events' => null], 'event'];
        yield 'events に別のイベントしか無い' => [['events' => ['https://example.com/other' => []]], 'event'];
        yield 'nonce がある' => [['nonce' => 'n'], 'nonce'];
        yield 'sub も sid も無い' => [['sub' => null, 'sid' => null], 'neither sub nor sid'];
        yield '期限切れ' => [['exp' => TestIdp::NOW - 61], 'cannot be verified'];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidTokens')]
    public function testRejectsInvalidTokens(array $overrides, string $expectedMessage): void
    {
        $this->expectException(OidcException::class);
        $this->expectExceptionMessage($expectedMessage);

        self::verifier()->verify(self::$idp->logoutToken($overrides), TestIdp::NOW);
    }

    public function testRejectsIdTokenPresentedAsLogoutToken(): void
    {
        // ログイン時の ID Token を流用して、他人をログアウトさせることはできない
        $this->expectException(OidcException::class);

        self::verifier()->verify(self::$idp->idToken(), TestIdp::NOW);
    }

    public function testRejectsTokenSignedByAnotherKey(): void
    {
        $attacker = new TestIdp(self::$idp->kid);

        $this->expectException(OidcException::class);
        $this->expectExceptionMessage('cannot be verified');

        self::verifier()->verify($attacker->logoutToken(), TestIdp::NOW);
    }
}
