<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

/**
 * Verifies a Logout Token (OpenID Connect Back-Channel Logout 1.0, section 2.6).
 */
final class LogoutTokenVerifier
{
    private const EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    public function __construct(
        private readonly Discovery $discovery,
        private readonly JwtVerifier $jwt,
        private readonly string $issuer,
        private readonly string $clientId,
    ) {
    }

    /** @throws OidcException when verification fails */
    public function verify(#[\SensitiveParameter] string $logoutToken, ?int $now = null): LogoutNotice
    {
        $metadata = $this->discovery->fetch($this->issuer);
        $claims = $this->jwt->verify($logoutToken, $metadata, $now ?? time(), 'Logout Token');

        if (($claims['iss'] ?? null) !== $metadata->issuer) {
            throw new OidcException('Logout Token iss does not match the issuer');
        }
        $audiences = is_array($claims['aud'] ?? null) ? $claims['aud'] : [$claims['aud'] ?? null];
        if (!in_array($this->clientId, $audiences, true)) {
            throw new OidcException('Logout Token aud does not contain this client');
        }
        if (!is_numeric($claims['iat'] ?? null)) {
            throw new OidcException('Logout Token has no iat');
        }
        // The events claim that marks a logout notice is required. A token without it (an ID Token,
        // for example) is not accepted as a logout notice.
        if (!is_array($claims['events'] ?? null) || !array_key_exists(self::EVENT, $claims['events'])) {
            throw new OidcException('Logout Token has no back-channel logout event');
        }
        // A token with a nonce is not a Logout Token (the rule exists to stop ID Tokens being reused).
        if (array_key_exists('nonce', $claims)) {
            throw new OidcException('Logout Token must not contain a nonce');
        }

        $subject = is_string($claims['sub'] ?? null) ? $claims['sub'] : '';
        $sessionId = is_string($claims['sid'] ?? null) ? $claims['sid'] : '';
        if ($subject === '' && $sessionId === '') {
            throw new OidcException('Logout Token has neither sub nor sid');
        }

        return new LogoutNotice($metadata->issuer, $subject, $sessionId);
    }
}
