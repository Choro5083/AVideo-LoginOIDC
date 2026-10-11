<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Tests\Oidc;

use LoginOIDC\Oidc\Discovery;
use LoginOIDC\Oidc\IdTokenVerifier;
use LoginOIDC\Oidc\JwksProvider;
use LoginOIDC\Oidc\JwtVerifier;
use LoginOIDC\Oidc\LoginFlow;
use LoginOIDC\Oidc\OidcException;
use LoginOIDC\Oidc\PendingLogin;
use LoginOIDC\Oidc\Pkce;
use LoginOIDC\Oidc\SecretBasicAuthenticator;
use LoginOIDC\Oidc\TokenClient;
use LoginOIDC\Oidc\UserInfoClient;
use LoginOIDC\Tests\Support\ArrayCache;
use LoginOIDC\Tests\Support\FakeHttpClient;
use LoginOIDC\Tests\Support\TestIdp;
use PHPUnit\Framework\TestCase;

final class LoginFlowTest extends TestCase
{
    private const REDIRECT_URI = 'https://av.example.com/plugin/LoginOIDC/callback.php';

    private static TestIdp $idp;

    public static function setUpBeforeClass(): void
    {
        self::$idp = new TestIdp();
    }

    /**
     * @param array<string, mixed>|null $tokenBody response of the token endpoint. An ID Token that verifies when omitted
     * @param array<string, mixed>|null $userInfo  when given, UserInfo is read too
     */
    private static function http(?array $tokenBody = null, ?array $userInfo = null): FakeHttpClient
    {
        return new FakeHttpClient([
            TestIdp::ISSUER . '/.well-known/openid-configuration' => FakeHttpClient::json(TestIdp::discoveryDocument()),
            TestIdp::ISSUER . '/certs' => FakeHttpClient::json(self::$idp->jwks()),
            TestIdp::ISSUER . '/token' => FakeHttpClient::json(
                $tokenBody ?? ['id_token' => self::$idp->idToken(), 'access_token' => 'access-1'],
            ),
            TestIdp::ISSUER . '/userinfo' => FakeHttpClient::json($userInfo ?? []),
        ]);
    }

    private static function flow(FakeHttpClient $http, bool $useUserInfo = false): LoginFlow
    {
        $cache = new ArrayCache();

        return new LoginFlow(
            new Discovery($http, $cache),
            new TokenClient($http, new SecretBasicAuthenticator(TestIdp::CLIENT_ID, 'secret')),
            new IdTokenVerifier(new JwtVerifier(new JwksProvider($http, $cache), 60)),
            $useUserInfo ? new UserInfoClient($http) : null,
            TestIdp::ISSUER,
            TestIdp::CLIENT_ID,
            self::REDIRECT_URI,
            ['openid', 'profile', 'email'],
        );
    }

    private static function pending(int $createdAt = TestIdp::NOW): PendingLogin
    {
        return new PendingLogin('state-1', 'nonce-1', 'verifier-1', '/video/1', $createdAt);
    }

    public function testStartBuildsAuthorizationRequest(): void
    {
        [$url, $pending] = self::flow(self::http())->start('/video/1', TestIdp::NOW);

        self::assertStringStartsWith(TestIdp::ISSUER . '/auth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('code', $query['response_type']);
        self::assertSame(TestIdp::CLIENT_ID, $query['client_id']);
        self::assertSame(self::REDIRECT_URI, $query['redirect_uri']);
        self::assertSame('openid profile email', $query['scope']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame($pending->state, $query['state']);
        self::assertSame($pending->nonce, $query['nonce']);
        self::assertSame(Pkce::fromVerifier($pending->codeVerifier)->challenge, $query['code_challenge']);
        self::assertStringNotContainsString($pending->codeVerifier, $url, 'the verifier is not sent');
        self::assertSame('/video/1', $pending->returnTo);
    }

    public function testStartGeneratesFreshValuesEachTime(): void
    {
        $flow = self::flow(self::http());
        [, $first] = $flow->start('/');
        [, $second] = $flow->start('/');

        self::assertNotSame($first->state, $second->state);
        self::assertNotSame($first->nonce, $second->nonce);
        self::assertNotSame($first->codeVerifier, $second->codeVerifier);
    }

    public function testFinishReturnsVerifiedLogin(): void
    {
        $http = self::http();

        $login = self::flow($http)->finish(self::pending(), ['state' => 'state-1', 'code' => 'code-1'], TestIdp::NOW);

        self::assertSame(TestIdp::ISSUER, $login->issuer);
        self::assertSame('user-1', $login->subject);
        self::assertSame('session-1', $login->sessionId);
        self::assertSame('/video/1', $login->returnTo);
        self::assertSame('alice', $login->claims['preferred_username']);
        $tokenRequest = array_values(array_filter($http->requests, static fn (array $r): bool => $r['form'] !== []))[0];
        self::assertSame('verifier-1', $tokenRequest['form']['code_verifier']);
    }

    public function testFinishMergesUserInfoButKeepsIdTokenClaims(): void
    {
        $http = self::http(userInfo: ['sub' => 'user-1', 'preferred_username' => 'overwritten', 'email' => 'alice@example.com']);

        $login = self::flow($http, useUserInfo: true)
            ->finish(self::pending(), ['state' => 'state-1', 'code' => 'code-1'], TestIdp::NOW);

        self::assertSame('alice@example.com', $login->claims['email'], 'claims missing from the ID Token come from UserInfo');
        self::assertSame('alice', $login->claims['preferred_username'], 'the ID Token wins when both have it');
    }

    public function testFinishRejectsUserInfoForAnotherSubject(): void
    {
        $http = self::http(userInfo: ['sub' => 'someone-else', 'email' => 'eve@example.com']);

        $this->expectException(OidcException::class);
        $this->expectExceptionMessage('UserInfo sub');

        self::flow($http, useUserInfo: true)->finish(self::pending(), ['state' => 'state-1', 'code' => 'code-1'], TestIdp::NOW);
    }

    public function testFinishRejectsStateMismatchBeforeAnyRequest(): void
    {
        $http = self::http();

        try {
            self::flow($http)->finish(self::pending(), ['state' => 'forged', 'code' => 'code-1'], TestIdp::NOW);
            self::fail('accepted a callback with a different state');
        } catch (OidcException $e) {
            self::assertStringContainsString('state', $e->getMessage());
        }
        self::assertSame([], $http->requests, 'stops before contacting the provider');
    }

    public function testFinishRejectsCallbackWithoutPendingLogin(): void
    {
        $this->expectException(OidcException::class);
        $this->expectExceptionMessage('state');

        self::flow(self::http())->finish(null, ['state' => 'state-1', 'code' => 'code-1'], TestIdp::NOW);
    }

    public function testFinishRejectsStalePendingLogin(): void
    {
        $this->expectException(OidcException::class);
        $this->expectExceptionMessage('too long ago');

        self::flow(self::http())->finish(
            self::pending(TestIdp::NOW - PendingLogin::MAX_AGE_SECONDS - 1),
            ['state' => 'state-1', 'code' => 'code-1'],
            TestIdp::NOW,
        );
    }

    public function testFinishReportsProviderError(): void
    {
        $this->expectException(OidcException::class);
        $this->expectExceptionMessage('access_denied');

        self::flow(self::http())->finish(
            self::pending(),
            ['state' => 'state-1', 'error' => 'access_denied', 'error_description' => 'User cancelled'],
            TestIdp::NOW,
        );
    }

    public function testFinishRejectsTokenWithWrongNonce(): void
    {
        // an ID Token issued for another sign-in is injected
        $http = self::http(['id_token' => self::$idp->idToken(nonce: 'other-login'), 'access_token' => 'a']);

        $this->expectException(OidcException::class);
        $this->expectExceptionMessage('nonce');

        self::flow($http)->finish(self::pending(), ['state' => 'state-1', 'code' => 'code-1'], TestIdp::NOW);
    }

    public function testPendingLoginSurvivesSessionRoundTrip(): void
    {
        $pending = self::pending();

        self::assertEquals($pending, PendingLogin::fromArray($pending->toArray()));
        self::assertNull(PendingLogin::fromArray(null));
        self::assertNull(PendingLogin::fromArray(['state' => 'only-state']));
        self::assertNull(PendingLogin::fromArray(['state' => '', 'nonce' => 'n', 'codeVerifier' => 'v', 'returnTo' => '', 'createdAt' => 1]));
    }
}
