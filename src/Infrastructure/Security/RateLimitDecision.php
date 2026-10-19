<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Security;

/**
 * What the rate limiter concluded about one sign-in attempt.
 *
 * Three shapes, and the middle one is the interesting one:
 *
 *  - **allowed, no delay** — the ordinary case.
 *  - **allowed, with a delay** — the attempt is answered, just not quickly. An
 *    attacker's throughput falls; a person who mistyped waits a quarter of a
 *    second and does not notice.
 *  - **refused** — `429`, with `Retry-After`.
 *
 * The delay is *carried* rather than *applied* here, so that the object stays a
 * value and the waiting happens in one place that a test can watch.
 */
final readonly class RateLimitDecision
{
    private function __construct(
        public bool $allowed,
        public int $delayMilliseconds,
        public int $retryAfterSeconds,
        public string $reason,
    ) {
    }

    public static function allowed(int $delayMilliseconds = 0): self
    {
        return new self(true, max(0, $delayMilliseconds), 0, '');
    }

    public static function refused(int $retryAfterSeconds, string $reason): self
    {
        return new self(false, 0, max(1, $retryAfterSeconds), $reason);
    }
}
