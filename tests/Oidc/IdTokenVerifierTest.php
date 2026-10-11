<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Tests\Oidc;

use Firebase\JWT\JWT;
use LoginOIDC\Oidc\IdTokenVerifier;
use LoginOIDC\Oidc\JwksProvider;
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

        return new IdTokenVerifier(new JwksProvider($http, new ArrayCache()), $skew);
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
        // 期限を 30 秒過ぎているが、許容は 60 秒
        $claims = self::verify(self::$idp->idToken(), now: TestIdp::NOW + 330);

        self::assertSame('user-1', $claims['sub']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidClaims(): iterable
    {
        yield 'iss が違う' => [['iss' => 'https://evil.example.com'], 'iss'];
        yield 'aud が違う' => [['aud' => 'other-client'], 'aud'];
        yield 'aud が複数で azp が無い' => [['aud' => [TestIdp::CLIENT_ID, 'other']], 'azp'];
        yield 'azp が別の client' => [['azp' => 'other-client'], 'azp'];
        yield 'nonce が違う' => [['nonce' => 'attacker'], 'nonce'];
        yield 'nonce が無い' => [['nonce' => null], 'nonce'];
        yield 'sub が無い' => [['sub' => null], 'sub'];
        yield 'sub が空' => [['sub' => ''], 'sub'];
        yield 'sub が長すぎる' => [['sub' => str_repeat('a', 256)], 'sub'];
        yield 'exp が無い' => [['exp' => null], 'exp'];
        yield 'iat が無い' => [['iat' => null], 'iat'];
        yield '期限切れ' => [['exp' => TestIdp::NOW - 61], 'cannot be verified'];
        yield '未来に発行された' => [['iat' => TestIdp::NOW + 61], 'cannot be verified'];
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
            self::fail('HS256 を受け入れた');
        } catch (OidcException $e) {
            self::assertStringContainsString('HS256', $e->getMessage());
        }
        self::assertSame([], $http->requests, '鍵を取りに行く前に弾く');
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
        // 1回目は古い鍵、取り直すと新しい鍵が返る（IdP が鍵を入れ替えた直後）
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
            self::fail('知らない鍵のトークンを受け入れた');
        } catch (OidcException $e) {
            self::assertStringContainsString('key-1', $e->getMessage());
        }
        self::assertSame(2, $http->countRequestsTo(self::JWKS_URI), '取り直すのは1度だけ');
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
            // 失敗しても元に戻ることを確かめる
        }

        self::assertSame(7, JWT::$leeway);
        self::assertNull(JWT::$timestamp);
        JWT::$leeway = 0;
    }
}
