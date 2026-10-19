<?php

declare(strict_types=1);

namespace Mailadmin\Runtime;

/**
 * Waits, so that a test does not have to.
 *
 * The one caller is the sign-in rate limiter, which architecture 5.6 asks to
 * answer a failing attempt more slowly each time. That behaviour has to be
 * testable, and a test suite that actually waits several seconds to prove it is
 * a test suite that gets skipped.
 *
 * Deliberately in milliseconds. The delays involved are a few hundred at most —
 * see {@see \Mailadmin\Infrastructure\Security\RateLimiter} for why they are
 * capped rather than doubling indefinitely.
 */
interface Sleeper
{
    public function sleepMilliseconds(int $milliseconds): void;
}
