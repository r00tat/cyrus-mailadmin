<?php

declare(strict_types=1);

namespace Mailadmin\Application\Auth;

use Mailadmin\Infrastructure\Security\AccessToken;
use Mailadmin\Infrastructure\Security\RefreshToken;
use Mailadmin\Infrastructure\Security\RefreshTokenRecord;
use Mailadmin\Infrastructure\Security\RefreshTokenStore;
use Mailadmin\Runtime\Clock;

/**
 * Mints refresh tokens and writes them down — the half of a session that has
 * state.
 *
 * ## Beginning versus continuing
 *
 * {@see begin()} starts a **family** with a fresh twelve-hour deadline.
 * {@see continueFamily()} adds a successor to an existing one and **copies the
 * old deadline unchanged**.
 *
 * That copy is the point. If a rotation extended the deadline, a session could
 * be kept alive forever by refreshing, and "twelve hours absolute" would mean
 * "twelve hours idle" — which is precisely the property that limits what a
 * stolen refresh token is worth. The two methods are separate so that the
 * difference is a choice at the call site rather than a parameter someone
 * defaults wrongly.
 */
final readonly class SessionFactory
{
    /** Bytes of randomness in a family id; hex-encoded into `CHAR(32)`. */
    private const int FAMILY_BYTES = 16;

    public function __construct(
        private RefreshTokenStore $store,
        private Clock $clock,
        private int $refreshTokenTtl,
    ) {
    }

    public function begin(AccessToken $accessToken, string $subject): IssuedSession
    {
        $now = $this->clock->now();

        return $this->mint(
            accessToken: $accessToken,
            subject: $subject,
            family: bin2hex(random_bytes(self::FAMILY_BYTES)),
            expiresAt: $now->setTimestamp($now->getTimestamp() + $this->refreshTokenTtl),
        );
    }

    public function continueFamily(AccessToken $accessToken, RefreshTokenRecord $previous): IssuedSession
    {
        return $this->mint(
            accessToken: $accessToken,
            subject: $previous->subject,
            family: $previous->family,
            // Not recomputed. See the class comment.
            expiresAt: $previous->expiresAt,
        );
    }

    private function mint(
        AccessToken $accessToken,
        string $subject,
        string $family,
        \DateTimeImmutable $expiresAt,
    ): IssuedSession {
        $refresh = RefreshToken::generate();

        $this->store->store(new RefreshTokenRecord(
            tokenHash: $refresh->hash(),
            family: $family,
            subject: $subject,
            issuedAt: $this->clock->now(),
            expiresAt: $expiresAt,
        ));

        return new IssuedSession($accessToken, $refresh, $expiresAt);
    }
}
