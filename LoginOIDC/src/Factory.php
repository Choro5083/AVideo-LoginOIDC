<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC;

use LoginOIDC\AVideo\AVideoIdentityStore;
use LoginOIDC\AVideo\AVideoUserDirectory;
use LoginOIDC\Http\CurlHttpClient;
use LoginOIDC\Http\HttpClient;
use LoginOIDC\Oidc\Cache;
use LoginOIDC\Oidc\ClientAuthenticator;
use LoginOIDC\Oidc\Discovery;
use LoginOIDC\Oidc\IdTokenVerifier;
use LoginOIDC\Oidc\JwksProvider;
use LoginOIDC\Oidc\JwtVerifier;
use LoginOIDC\Oidc\LoginFlow;
use LoginOIDC\Oidc\LogoutTokenVerifier;
use LoginOIDC\Oidc\SecretBasicAuthenticator;
use LoginOIDC\Oidc\SecretPostAuthenticator;
use LoginOIDC\Oidc\TokenClient;
use LoginOIDC\Oidc\UserInfoClient;
use LoginOIDC\User\Provisioner;

/** 設定から、処理に使うオブジェクトを組み立てる。 */
final class Factory
{
    public static function httpClient(Settings $settings): HttpClient
    {
        return new CurlHttpClient($settings->caCertFile);
    }

    /** @throws InvalidSettingException シークレットを読めないとき */
    public static function clientAuthenticator(Settings $settings): ClientAuthenticator
    {
        $secret = $settings->resolveClientSecret();

        return match ($settings->clientAuthMethod) {
            ClientAuthMethod::SecretBasic => new SecretBasicAuthenticator($settings->clientId, $secret),
            ClientAuthMethod::SecretPost => new SecretPostAuthenticator($settings->clientId, $secret),
        };
    }

    /** @throws InvalidSettingException */
    public static function loginFlow(Settings $settings, string $redirectUri, Cache $cache): LoginFlow
    {
        $http = self::httpClient($settings);

        return new LoginFlow(
            new Discovery($http, $cache, $settings->allowInsecureHttp),
            new TokenClient($http, self::clientAuthenticator($settings)),
            new IdTokenVerifier(new JwtVerifier(new JwksProvider($http, $cache), $settings->clockSkewSeconds)),
            $settings->useUserInfo ? new UserInfoClient($http) : null,
            $settings->issuer,
            $settings->clientId,
            $redirectUri,
            $settings->scopes,
        );
    }

    public static function logoutTokenVerifier(Settings $settings, Cache $cache): LogoutTokenVerifier
    {
        $http = self::httpClient($settings);

        return new LogoutTokenVerifier(
            new Discovery($http, $cache, $settings->allowInsecureHttp),
            new JwtVerifier(new JwksProvider($http, $cache), $settings->clockSkewSeconds),
            $settings->issuer,
            $settings->clientId,
        );
    }

    public static function provisioner(Settings $settings): Provisioner
    {
        return new Provisioner($settings, new AVideoIdentityStore(), new AVideoUserDirectory());
    }
}
