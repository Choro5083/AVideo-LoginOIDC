<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC;

use LoginOIDC\AVideo\AVideoIdentityStore;
use LoginOIDC\AVideo\AVideoUserDirectory;
use LoginOIDC\AVideo\SessionRegistry;
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
use LoginOIDC\Sync\KeycloakDirectory;
use LoginOIDC\Sync\Synchronizer;
use LoginOIDC\User\Provisioner;

/** Builds the objects that do the work from the settings. */
final class Factory
{
    public static function httpClient(Settings $settings): HttpClient
    {
        return new CurlHttpClient($settings->caCertFile);
    }

    /** @throws InvalidSettingException when the secret cannot be read */
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

    /**
     * @param  string $syncSecretFromEnvironment value of the environment variable LOGINOIDC_SYNC_CLIENT_SECRET
     * @throws InvalidSettingException
     * @throws \LoginOIDC\Oidc\OidcException when discovery fails
     */
    public static function synchronizer(Settings $settings, Cache $cache, string $syncSecretFromEnvironment): Synchronizer
    {
        if ($settings->syncClientId === '') {
            throw new InvalidSettingException('syncClientId: not configured');
        }
        $adminUrl = $settings->syncAdminUrl !== ''
            ? $settings->syncAdminUrl
            : KeycloakDirectory::adminUrlFromIssuer($settings->issuer);
        if ($adminUrl === '') {
            throw new InvalidSettingException('syncAdminUrl: cannot be derived from the issuer, set it explicitly');
        }

        $http = self::httpClient($settings);
        $metadata = (new Discovery($http, $cache, $settings->allowInsecureHttp))->fetch($settings->issuer);
        $users = new AVideoUserDirectory();

        return new Synchronizer(
            $settings,
            new AVideoIdentityStore(),
            $users,
            new SessionRegistry(),
            new KeycloakDirectory(
                $http,
                new SecretBasicAuthenticator(
                    $settings->syncClientId,
                    $settings->resolveSyncClientSecret($syncSecretFromEnvironment),
                ),
                $metadata->tokenEndpoint,
                $adminUrl,
                $settings->syncRoleSource,
                $settings->syncRoleClientId,
            ),
        );
    }

    public static function provisioner(Settings $settings): Provisioner
    {
        return new Provisioner($settings, new AVideoIdentityStore(), new AVideoUserDirectory());
    }
}
