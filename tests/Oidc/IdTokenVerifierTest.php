<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Tests\Oidc;

use Firebase\JWT\JWT;
use LoginOIDC\Oidc\IdTokenVerifier;
use LoginOIDC\Oidc\JwksProvider;
use LoginOIDC\Oidc\JwtVerifier;
use LoginOIDC\Oidc\OidcException;
use LoginOIDC\Tests\Support\ArrayCache;
use LoginOIDC\Tests\Support\FakeHttpClient;
use LoginOIDC\Tests\Support\TestIdp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdTokenVerifierTest extends TestCase
{
    private const JWKS_URI = TestIdp::ISSUER . '/certs';

    private static TestIdp $idp;

    public static function setUpBeforeClass(): void
    {
        self::$idp = new TestIdp();
    }

    /** @param array{keys: list<array<string, string>>}|null $jwks */
    private static function verifier(?FakeHttpClient $http = null, ?array $jwks = null, int $skew = 60): IdTokenVerifier
    {
        $http ??= new FakeHttpClient([self::JWKS_URI => FakeHttpClient::json($jwks ?? self::$idp->jwks())]);

        return new IdTokenVerifier(new JwtVerifier(new JwksProvider($http, new ArrayCache()), $skew));
    }

    /** @return array<string, mixed> */
    private static function verify(string $idToken, ?IdTokenVerifier $verifier = null, int $now = TestIdp::NOW): array
    {
        return ($verifier ?? self::verifier())->verify($idToken, TestIdp::metadata(), TestIdp::CLIENT_ID, 'nonce-1', $now);
    }

    public function testAcceptsValidToken(): void
    {
        $claims = self::verify(self::$idp->idToken());

        self::assertSame('user-1', $claims['sub']);
        self::assertSame('alice', $claims['preferred_username']);
    }

    public function testAcceptsAudienceArrayWhenAzpIsThisClient(): void
    {
        $claims = self::verify(self::$idp->idToken(['aud' => [TestIdp::CLIENT_ID, 'other'], 'azp' => TestIdp::CLIENT_ID]));

        self::assertSame('user-1', $claims['sub']);
    }

    public function testAcceptsJwksWithoutAlg(): void
    {
        $claims = self::verify(self::$idp->idToken(), self::verifier(jwks: self::$idp->jwks(withAlg: false)));

        self::assertSame('user-1', $claims['sub']);
    }

    public function testToleratesClockSkewWithinTheLimit(): void
    {
        // 30 seconds past the expiry, with 60 seconds tolerated
        $claims = self::verify(self::$idp->idToken(), now: TestIdp::NOW + 330);

        self::assertSame('user-1', $claims['sub']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidClaims(): iterable
    {
        yield 'wrong iss' => [['iss' => 'https://evil.example.com'], 'iss'];
        yield 'wrong aud' => [['aud' => 'other-client'], 'aud'];
        yield 'several audiences and no azp' => [['aud' => [TestIdp::CLIENT_ID, 'other']], 'azp'];
        yield 'azp is another client' => [['azp' => 'other-client'], 'azp'];
        yield 'wrong nonce' => [['nonce' => 'attacker'], 'nonce'];
        yield 'no nonce' => [['nonce' => null], 'nonce'];
        yield 'no sub' => [['sub' => null], 'sub'];
        yield 'empty sub' => [['sub' => ''], 'sub'];
        yield 'sub too long' => [['sub' => str_repeat('a', 256)], 'sub'];
        yield 'no exp' => [['exp' => null], 'exp'];
        yield 'no iat' => [['iat' => null], 'iat'];
        yield 'expired' => [['exp' => TestIdp::NOW - 61], 'cannot be verified'];
        yield 'issued in the future' => [['iat' => TestIdp::NOW + 61], 'cannot be verified'];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidClaims')]
    public function testRejectsInvalidClaims(array $overrides, string $expectedMessage): void
    {
        $this->expectException(OidcException::class);
        $this->expectExceptionMessage($expectedMessage);

        self::verify(self::$idp->idToken($overrides));
    }

    public function testRejectsTokenSignedByAnotherKey(): void
    {
        $attacker = new TestIdp(self::$idp->kid);

        $this->expectException(OidcException::class);
        $this->expectExceptionMessage('cannot be verified');

        self::verify($attacker->idToken());
    }

    public function testRejectsSymmetricAlgorithmBeforeTouchingKeys(): void
    {
        $http = new FakeHttpClient();
        $token = JWT::encode(['iss' => TestIdp::ISSUER], 'shared-secret-shared-secret-123456', 'HS256', self::$idp->kid);

        try {
            self::verify($token, self::verifier($http));
            self::fail('accepted HS256');
        } catch (OidcException $e) {
            self::assertStringContainsString('HS256', $e->getMessage());
        }
        self::assertSame([], $http->requests, 'rejected before fetching any key');
    }

    public function testRejectsUnsignedToken(): void
    {
        $header = rtrim(strtr(base64_encode('{"alg":"none"}'), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode('{"sub":"user-1"}'), '+/', '-_'), '=');

        $this->expectException(OidcException::class);
        $this->expectExceptionMessage('none');

        self::verify("{$header}.{$payload}.");
    }

    public function testRefetchesJwksOnceWhenKidIsUnknown(): void
    {
        // the old key first, the new key on refetch (right after the provider rotated its keys)
        $old = new TestIdp('old-key');
        $http = new FakeHttpClient([self::JWKS_URI => [
            FakeHttpClient::json($old->jwks()),
            FakeHttpClient::json(self::$idp->jwks()),
        ]]);

        $claims = self::verify(self::$idp->idToken(), self::verifier($http));

        self::assertSame('user-1', $claims['sub']);
        self::assertSame(2, $http->countRequestsTo(self::JWKS_URI));
    }

    public function testFailsWhenKidIsStillUnknownAfterRefetch(): void
    {
        $old = new TestIdp('old-key');
        $http = new FakeHttpClient([self::JWKS_URI => [
            FakeHttpClient::json($old->jwks()),
            FakeHttpClient::json($old->jwks()),
        ]]);

        try {
            self::verify(self::$idp->idToken(), self::verifier($http));
            self::fail('accepted a token signed with an unknown key');
        } catch (OidcException $e) {
            self::assertStringContainsString('key-1', $e->getMessage());
        }
        self::assertSame(2, $http->countRequestsTo(self::JWKS_URI), 'refetched only once');
    }

    public function testDoesNotRefetchJwksAgainRightAfterARefetch(): void
    {
        // tokens with unknown kids keep coming, but refetches from the provider are spaced out
        $old = new TestIdp('old-key');
        $http = new FakeHttpClient([self::JWKS_URI => [
            FakeHttpClient::json($old->jwks()),
            FakeHttpClient::json($old->jwks()),
        ]]);
        $verifier = self::verifier($http);

        foreach ([1, 2, 3] as $attempt) {
            try {
                self::verify(self::$idp->idToken(), $verifier);
                self::fail('accepted a token signed with an unknown key');
            } catch (OidcException) {
                // expected
            }
        }

        self::assertSame(2, $http->countRequestsTo(self::JWKS_URI), 'only the first fetch and one refetch');
    }

    public function testIgnoresEncryptionKeys(): void
    {
        $jwks = self::$idp->jwks();
        $jwks['keys'][0]['use'] = 'enc';

        $this->expectException(OidcException::class);
        $this->expectExceptionMessage('not in the JWKS');

        self::verify(self::$idp->idToken(), self::verifier(new FakeHttpClient([self::JWKS_URI => [
            FakeHttpClient::json($jwks),
            FakeHttpClient::json($jwks),
        ]])));
    }

    public function testRestoresLibraryGlobalsAfterVerification(): void
    {
        JWT::$leeway = 7;
        JWT::$timestamp = null;

        try {
            self::verify(self::$idp->idToken(['exp' => TestIdp::NOW - 1000]));
        } catch (OidcException) {
            // they must be restored after a failure too
        }

        self::assertSame(7, JWT::$leeway);
        self::assertNull(JWT::$timestamp);
        JWT::$leeway = 0;
    }
}
