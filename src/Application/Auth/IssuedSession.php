<?php

declare(strict_types=1);

namespace Mailadmin\Application\Auth;

use DateTimeImmutable;
use Mailadmin\Infrastructure\Security\AccessToken;
use Mailadmin\Infrastructure\Security\RefreshToken;

/**
 * What a successful sign-in or refresh produces: the pair, and when the refresh
 * half stops being usable.
 *
 * The refresh token is a plaintext value that exists **only** in this object and
 * in the response that carries it — the store has its digest. That is why it is
 * here rather than being read back from the store afterwards: there is nothing
 * to read back.
 *
 * `refreshExpiresAt` is the family deadline, which a rotation copies rather than
 * extends. The cookie's `Max-Age` is derived from it, so that the browser stops
 * sending a token at the moment it stops working instead of a session cookie
 * outliving it.
 */
final readonly class IssuedSession
{
    public function __construct(
        public AccessToken $accessToken,
        public RefreshToken $refreshToken,
        public DateTimeImmutable $refreshExpiresAt,
    ) {
    }

    public function refreshExpiresIn(DateTimeImmutable $now): int
    {
        return max(0, $this->refreshExpiresAt->getTimestamp() - $now->getTimestamp());
    }
}
