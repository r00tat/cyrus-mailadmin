<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Security;

use DateTimeImmutable;

/**
 * One row of `mailadmin_refresh_token`.
 *
 * The four state questions are methods rather than comparisons at the call
 * site, because the difference between them is the whole security property:
 *
 *  - **expired** — the family's twelve hours are up. Sign in again.
 *  - **revoked** — someone signed out, or a reuse was detected. Sign in again.
 *  - **consumed** — this token was already exchanged. Seeing it a second time
 *    means it was copied, and the answer is to revoke the entire family, not
 *    just to refuse this request.
 *  - **usable** — none of the above.
 *
 * Getting "consumed" confused with "expired" would turn the reuse alarm into a
 * shrug, which is why they are separate columns and separate questions.
 */
final readonly class RefreshTokenRecord
{
    public function __construct(
        public string $tokenHash,
        public string $family,
        public string $subject,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $consumedAt = null,
        public ?DateTimeImmutable $revokedAt = null,
    ) {
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    public function isConsumed(): bool
    {
        return $this->consumedAt !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isUsable(DateTimeImmutable $now): bool
    {
        return !$this->isConsumed() && !$this->isRevoked() && !$this->isExpired($now);
    }

    public function consumedAt(DateTimeImmutable $at): self
    {
        return new self(
            tokenHash: $this->tokenHash,
            family: $this->family,
            subject: $this->subject,
            issuedAt: $this->issuedAt,
            expiresAt: $this->expiresAt,
            consumedAt: $at,
            revokedAt: $this->revokedAt,
        );
    }

    public function revokedAt(DateTimeImmutable $at): self
    {
        return new self(
            tokenHash: $this->tokenHash,
            family: $this->family,
            subject: $this->subject,
            issuedAt: $this->issuedAt,
            expiresAt: $this->expiresAt,
            consumedAt: $this->consumedAt,
            revokedAt: $this->revokedAt ?? $at,
        );
    }
}
