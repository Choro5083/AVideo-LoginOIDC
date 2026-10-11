<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Tests\Session;

use LoginOIDC\Session\SessionMarker;
use LoginOIDC\Session\SessionPolicy;
use LoginOIDC\Session\SessionState;
use PHPUnit\Framework\TestCase;

final class SessionPolicyTest extends TestCase
{
    private static function marker(int $loginAt = 1000): SessionMarker
    {
        return new SessionMarker(7, 'sid-1', $loginAt, 'x.y.z');
    }

    public function testKeepsHealthySession(): void
    {
        self::assertNull(SessionPolicy::reasonToEnd(self::marker(), new SessionState(true, null, false)));
    }

    public function testEndsSessionOfDisabledOrMissingUser(): void
    {
        self::assertNotNull(SessionPolicy::reasonToEnd(self::marker(), new SessionState(false, null, false)));
    }

    public function testEndsSessionOfUserWhoWasUnlinked(): void
    {
        self::assertNotNull(SessionPolicy::reasonToEnd(self::marker(), new SessionState(true, null, false, linked: false)));
    }

    public function testEndsSessionWhoseProviderSessionWasLoggedOut(): void
    {
        self::assertNotNull(SessionPolicy::reasonToEnd(self::marker(), new SessionState(true, null, true)));
    }

    public function testEndsSessionsStartedBeforeTheUserWideLogout(): void
    {
        self::assertNotNull(SessionPolicy::reasonToEnd(self::marker(1000), new SessionState(true, 1001, false)));
        self::assertNotNull(
            SessionPolicy::reasonToEnd(self::marker(1000), new SessionState(true, 1000, false)),
            'the same second ends too, to stay on the safe side',
        );
    }

    public function testKeepsSessionStartedAfterTheUserWideLogout(): void
    {
        // a session started after every session was ended
        self::assertNull(SessionPolicy::reasonToEnd(self::marker(1002), new SessionState(true, 1001, false)));
    }

    public function testMarkerSurvivesSessionRoundTrip(): void
    {
        $marker = self::marker();

        self::assertEquals($marker, SessionMarker::fromArray($marker->toArray()));
        self::assertNull(SessionMarker::fromArray(null));
        self::assertNull(SessionMarker::fromArray(['userId' => '7', 'sessionId' => 's', 'loginAt' => 1, 'idToken' => 't']));
    }
}
