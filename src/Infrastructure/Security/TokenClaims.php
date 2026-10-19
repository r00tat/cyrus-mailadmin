<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Security;

use DateTimeImmutable;
use Mailadmin\Domain\Model\AdminRole;

/**
 * A verified access token, unpacked.
 *
 * Only reached through {@see TokenVerifier}: there is no way to build one from
 * an unchecked string, which is what keeps "we looked at the token" and "the
 * token is valid" from being confusable in the code that uses it.
 *
 * Five claims and no more (architecture 5.3). In particular **no domain scope**:
 * it is read per request from `domainadmin`, so that withdrawing an assignment
 * takes effect immediately rather than at the end of the token's hour. `role` is
 * here because it practically never changes — and the authentication middleware
 * still compares it against the database, so that the exception is caught too.
 */
final readonly class TokenClaims
{
    public function __construct(
        public string $subject,
        public AdminRole $role,
        public string $jti,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $expiresAt,
    ) {
    }

    public function hasExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    /**
     * Seconds left, floored at zero. For the `expires_at` this session reports,
     * not for a decision — {@see hasExpired()} makes those.
     */
    public function secondsRemaining(DateTimeImmutable $now): int
    {
        return max(0, $this->expiresAt->getTimestamp() - $now->getTimestamp());
    }
}
