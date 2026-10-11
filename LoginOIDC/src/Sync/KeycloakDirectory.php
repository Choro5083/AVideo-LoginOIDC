<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Sync;

use LoginOIDC\Http\HttpClient;
use LoginOIDC\Http\HttpException;
use LoginOIDC\Http\HttpResponse;
use LoginOIDC\Oidc\ClientAuthenticator;
use LoginOIDC\SyncRoleSource;

/**
 * Looks users up through Keycloak's Admin REST API.
 *
 * Assumes the sub of the ID Token is the Keycloak user ID (Keycloak's default).
 *
 * The permissions needed (roles of realm-management) depend on what is read.
 * - groups:      view-users and query-users
 * - realm roles:  the above plus view-realm
 * - client roles: the above plus view-clients and query-clients
 * view-clients can read the configuration of every client in the realm, secrets included.
 * To keep the permissions small, use groups.
 */
final class KeycloakDirectory implements RemoteDirectory
{
    private ?string $accessToken = null;
    private ?string $roleClientUuid = null;

    public function __construct(
        private readonly HttpClient $http,
        private readonly ClientAuthenticator $authenticator,
        private readonly string $tokenEndpoint,
        /** for example https://idp.example.com/admin/realms/example */
        private readonly string $adminUrl,
        private readonly SyncRoleSource $roleSource,
        private readonly string $roleClientId,
    ) {
    }

    /** Derives the Admin API URL from the issuer (.../realms/<name>). An empty string when it cannot. */
    public static function adminUrlFromIssuer(string $issuer): string
    {
        $position = strrpos($issuer, '/realms/');

        return $position === false
            ? ''
            : substr($issuer, 0, $position) . '/admin/realms/' . substr($issuer, $position + strlen('/realms/'));
    }

    public function lookup(string $subject): ?RemoteUser
    {
        $response = $this->get('/users/' . rawurlencode($subject));
        // Only a 404 means "gone". Mistaking any other failure for it would disable the user.
        if ($response->status === 404) {
            return null;
        }
        $user = $this->decode($response, "user {$subject}");

        $groups = $this->groupValues($subject);

        return new RemoteUser(
            enabled: ($user['enabled'] ?? null) === true,
            name: trim(self::text($user, 'firstName') . ' ' . self::text($user, 'lastName')),
            email: self::text($user, 'email'),
            emailVerified: is_bool($user['emailVerified'] ?? null) ? $user['emailVerified'] : null,
            roleValues: match ($this->roleSource) {
                SyncRoleSource::Groups => $groups,
                SyncRoleSource::RealmRoles => $this->roleNames("/users/{$subject}/role-mappings/realm/composite", 'realm roles'),
                SyncRoleSource::ClientRoles => $this->roleNames(
                    "/users/{$subject}/role-mappings/clients/{$this->roleClientUuid()}/composite",
                    'client roles',
                ),
            },
            groupValues: $groups,
        );
    }

    /**
     * Returns both the names and the paths of the user's groups. Which of them the provider puts
     * in the claim depends on its mapper, so a mapping written with either one matches.
     *
     * @return list<string>
     */
    private function groupValues(string $subject): array
    {
        $groups = $this->decodeList($this->get("/users/{$subject}/groups?briefRepresentation=true&max=1000"), 'groups');
        $values = [];
        foreach ($groups as $group) {
            foreach (['name', 'path'] as $key) {
                if (is_array($group) && is_string($group[$key] ?? null) && $group[$key] !== '') {
                    $values[] = $group[$key];
                }
            }
        }

        return array_values(array_unique($values));
    }

    /** @return list<string> */
    private function roleNames(string $path, string $what): array
    {
        $names = [];
        foreach ($this->decodeList($this->get($path), $what) as $role) {
            if (is_array($role) && is_string($role['name'] ?? null)) {
                $names[] = $role['name'];
            }
        }

        return $names;
    }

    private function roleClientUuid(): string
    {
        if ($this->roleClientUuid !== null) {
            return $this->roleClientUuid;
        }
        if ($this->roleClientId === '') {
            throw new SyncException('syncRoleClientId: required when the role source is client roles');
        }
        $clients = $this->decodeList($this->get('/clients?clientId=' . rawurlencode($this->roleClientId)), 'clients');
        $uuid = is_array($clients[0] ?? null) ? ($clients[0]['id'] ?? null) : null;
        if (!is_string($uuid) || $uuid === '') {
            throw new SyncException(
                "client {$this->roleClientId} was not found. The sync account needs view-clients and query-clients to read client roles"
            );
        }

        return $this->roleClientUuid = $uuid;
    }

    private function get(string $path): HttpResponse
    {
        try {
            $response = $this->http->request($this->adminUrl . $path, [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->accessToken(),
            ]);
        } catch (HttpException $e) {
            throw new SyncException("Admin API request failed: {$e->getMessage()}", 0, $e);
        }
        if ($response->status === 401 || $response->status === 403) {
            throw new SyncException(
                "Admin API denied the request (HTTP {$response->status}) for {$path}. Check the roles of the sync account"
            );
        }

        return $response;
    }

    private function accessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }
        [$headers, $form] = $this->authenticator->apply(
            ['Accept' => 'application/json'],
            ['grant_type' => 'client_credentials'],
        );
        try {
            $response = $this->http->request($this->tokenEndpoint, $headers, $form);
            $body = $response->json();
        } catch (HttpException $e) {
            throw new SyncException("cannot get a token for the sync account: {$e->getMessage()}", 0, $e);
        }
        $token = $body['access_token'] ?? null;
        if (!$response->isSuccess() || !is_string($token) || $token === '') {
            $error = is_string($body['error'] ?? null) ? $body['error'] : 'unknown';
            throw new SyncException("cannot get a token for the sync account (HTTP {$response->status}): {$error}");
        }

        return $this->accessToken = $token;
    }

    /** @return array<string, mixed> */
    private function decode(HttpResponse $response, string $what): array
    {
        if (!$response->isSuccess()) {
            throw new SyncException("Admin API returned HTTP {$response->status} for {$what}");
        }
        try {
            return $response->json();
        } catch (HttpException $e) {
            throw new SyncException("Admin API returned an unreadable response for {$what}: {$e->getMessage()}", 0, $e);
        }
    }

    /** @return list<mixed> */
    private function decodeList(HttpResponse $response, string $what): array
    {
        if (!$response->isSuccess()) {
            throw new SyncException("Admin API returned HTTP {$response->status} for {$what}");
        }
        $decoded = json_decode($response->body, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new SyncException("Admin API returned an unreadable response for {$what}");
        }

        return $decoded;
    }

    /** @param array<string, mixed> $user */
    private static function text(array $user, string $key): string
    {
        return is_string($user[$key] ?? null) ? $user[$key] : '';
    }
}
