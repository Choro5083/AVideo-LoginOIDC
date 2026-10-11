<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

/**
 * The Authorization Code Flow with PKCE.
 *
 * It touches neither the session nor HTTP input and output. The caller stores the
 * PendingLogin and passes in the query of the callback.
 */
final class LoginFlow
{
    /** @param list<string> $scopes */
    public function __construct(
        private readonly Discovery $discovery,
        private readonly TokenClient $tokens,
        private readonly IdTokenVerifier $verifier,
        private readonly ?UserInfoClient $userInfo,
        private readonly string $issuer,
        private readonly string $clientId,
        private readonly string $redirectUri,
        private readonly array $scopes,
    ) {
    }

    /**
     * Builds the authorization request.
     *
     * @return array{string, PendingLogin} [URL to redirect to at the provider, value to keep in the session]
     * @throws OidcException
     */
    public function start(string $returnTo, ?int $now = null): array
    {
        $metadata = $this->discovery->fetch($this->issuer);
        $pkce = Pkce::generate();
        $pending = new PendingLogin(
            state: Base64Url::encode(random_bytes(24)),
            nonce: Base64Url::encode(random_bytes(24)),
            codeVerifier: $pkce->verifier,
            returnTo: $returnTo,
            createdAt: $now ?? time(),
        );

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => implode(' ', $this->scopes),
            'state' => $pending->state,
            'nonce' => $pending->nonce,
            'code_challenge' => $pkce->challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
        $separator = str_contains($metadata->authorizationEndpoint, '?') ? '&' : '?';

        return [$metadata->authorizationEndpoint . $separator . $query, $pending];
    }

    /**
     * Handles the callback and verifies the result of the authentication.
     *
     * @param  PendingLogin|null     $pending value read from the session, or null
     * @param  array<string, mixed>  $query   query of the callback
     * @throws OidcException when verification fails
     */
    public function finish(?PendingLogin $pending, array $query, ?int $now = null): VerifiedLogin
    {
        $now ??= time();

        // Compare the state first. A request that does not match is not a sign-in we started.
        $state = $query['state'] ?? null;
        if ($pending === null || !is_string($state) || !hash_equals($pending->state, $state)) {
            throw new OidcException('state does not match (the sign-in must be restarted)');
        }
        if ($now - $pending->createdAt > PendingLogin::MAX_AGE_SECONDS) {
            throw new OidcException('the sign-in was started too long ago');
        }

        if (isset($query['error'])) {
            $error = is_string($query['error']) ? $query['error'] : 'unknown';
            $description = is_string($query['error_description'] ?? null) ? " ({$query['error_description']})" : '';
            throw new OidcException("the provider returned an error: {$error}{$description}");
        }

        $code = $query['code'] ?? null;
        if (!is_string($code) || $code === '') {
            throw new OidcException('callback has no code');
        }

        $metadata = $this->discovery->fetch($this->issuer);
        $tokens = $this->tokens->exchangeCode($metadata, $code, $this->redirectUri, $pending->codeVerifier);
        $claims = $this->verifier->verify($tokens->idToken, $metadata, $this->clientId, $pending->nonce, $now);
        $subject = (string) $claims['sub'];

        if ($this->userInfo !== null) {
            // Claims of the ID Token win. Only the ID Token is verified by a signature.
            $claims += $this->userInfo->fetch($metadata, $tokens->accessToken, $subject);
        }

        return new VerifiedLogin(
            issuer: $metadata->issuer,
            subject: $subject,
            claims: $claims,
            sessionId: is_string($claims['sid'] ?? null) ? $claims['sid'] : '',
            idToken: $tokens->idToken,
            returnTo: $pending->returnTo,
        );
    }
}
