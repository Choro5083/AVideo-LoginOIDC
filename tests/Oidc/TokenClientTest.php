<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Tests\Oidc;

use LoginOIDC\Oidc\OidcException;
use LoginOIDC\Oidc\Pkce;
use LoginOIDC\Oidc\SecretBasicAuthenticator;
use LoginOIDC\Oidc\SecretPostAuthenticator;
use LoginOIDC\Oidc\TokenClient;
use LoginOIDC\Tests\Support\FakeHttpClient;
use LoginOIDC\Tests\Support\TestIdp;
use PHPUnit\Framework\TestCase;

final class TokenClientTest extends TestCase
{
    private const TOKEN_URL = TestIdp::ISSUER . '/token';

    public function testPkceMatchesRfc7636Example(): void
    {
        // the values of RFC 7636, appendix B
        $pkce = Pkce::fromVerifier('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk');

        self::assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', $pkce->challenge);
    }

    public function testGeneratedVerifierIsLongEnough(): void
    {
        $pkce = Pkce::generate();

        self::assertGreaterThanOrEqual(43, strlen($pkce->verifier));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $pkce->verifier);
        self::assertNotSame(Pkce::generate()->verifier, $pkce->verifier);
    }

    public function testSecretBasicSendsEncodedCredentialsInHeader(): void
    {
        $http = new FakeHttpClient([self::TOKEN_URL => FakeHttpClient::json(['id_token' => 'x.y.z', 'access_token' => 'at'])]);
        $client = new TokenClient($http, new SecretBasicAuthenticator('my client', 'p@ss:word/+'));

        $tokens = $client->exchangeCode(TestIdp::metadata(), 'code-1', 'https://av.example.com/cb', 'verifier-1');

        self::assertSame('x.y.z', $tokens->idToken);
        self::assertSame('at', $tokens->accessToken);
        $request = $http->requests[0];
        // RFC 6749, section 2.3.1: form-encode before Base64
        self::assertSame('Basic ' . base64_encode('my+client:p%40ss%3Aword%2F%2B'), $request['headers']['Authorization']);
        self::assertSame(
            ['grant_type' => 'authorization_code', 'code' => 'code-1', 'redirect_uri' => 'https://av.example.com/cb', 'code_verifier' => 'verifier-1'],
            $request['form'],
        );
    }

    public function testSecretPostSendsCredentialsInBody(): void
    {
        $http = new FakeHttpClient([self::TOKEN_URL => FakeHttpClient::json(['id_token' => 'x.y.z'])]);
        $client = new TokenClient($http, new SecretPostAuthenticator('avideo', 'secret'));

        $tokens = $client->exchangeCode(TestIdp::metadata(), 'code-1', 'https://av.example.com/cb', 'verifier-1');

        self::assertSame('', $tokens->accessToken);
        self::assertArrayNotHasKey('Authorization', $http->requests[0]['headers']);
        self::assertSame('avideo', $http->requests[0]['form']['client_id']);
        self::assertSame('secret', $http->requests[0]['form']['client_secret']);
    }

    public function testRejectsAuthMethodTheProviderDoesNotSupport(): void
    {
        $document = ['token_endpoint_auth_methods_supported' => ['private_key_jwt']] + TestIdp::discoveryDocument();
        $http = new FakeHttpClient();

        try {
            (new TokenClient($http, new SecretBasicAuthenticator('avideo', 'secret')))
                ->exchangeCode(\LoginOIDC\Oidc\ProviderMetadata::fromArray($document), 'c', 'https://av.example.com/cb', 'v');
            self::fail('sent with a method the provider does not support');
        } catch (OidcException $e) {
            self::assertStringContainsString('client_secret_basic', $e->getMessage());
        }
        self::assertSame([], $http->requests, 'stops before sending the secret');
    }

    public function testReportsProviderErrorWithoutLeakingSecret(): void
    {
        $http = new FakeHttpClient([self::TOKEN_URL => FakeHttpClient::json(
            ['error' => 'invalid_grant', 'error_description' => 'Code not valid'],
            400,
        )]);

        try {
            (new TokenClient($http, new SecretBasicAuthenticator('avideo', 'top-secret')))
                ->exchangeCode(TestIdp::metadata(), 'code-1', 'https://av.example.com/cb', 'verifier-1');
            self::fail('treated an error response as success');
        } catch (OidcException $e) {
            self::assertStringContainsString('invalid_grant', $e->getMessage());
            self::assertStringContainsString('Code not valid', $e->getMessage());
            self::assertStringNotContainsString('top-secret', $e->getMessage());
            self::assertStringNotContainsString('verifier-1', $e->getMessage());
        }
    }

    public function testRejectsResponseWithoutIdToken(): void
    {
        $http = new FakeHttpClient([self::TOKEN_URL => FakeHttpClient::json(['access_token' => 'at'])]);

        $this->expectException(OidcException::class);
        $this->expectExceptionMessage('id_token');

        (new TokenClient($http, new SecretBasicAuthenticator('avideo', 'secret')))
            ->exchangeCode(TestIdp::metadata(), 'code-1', 'https://av.example.com/cb', 'verifier-1');
    }
}
