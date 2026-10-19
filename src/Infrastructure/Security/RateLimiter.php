<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Security;

use Mailadmin\Runtime\Clock;
use Mailadmin\Runtime\Sleeper;

/**
 * Makes guessing a password expensive (architecture 5.6).
 *
 * ## The numbers, and why they are these numbers
 *
 * All counting happens inside a fifteen-minute sliding window.
 *
 * | | Threshold | What happens |
 * |---|---|---|
 * | per user name | from the 3rd failure | growing delay, capped at one second |
 * | per user name | at the 10th failure | refused for the rest of the window |
 * | per client address | at the 50th failure | refused for the rest of the window |
 *
 * Ten is chosen to sit clearly above what a person does — three attempts at a
 * remembered password, a caps-lock realisation, a look at the password manager
 * — and far below what a dictionary needs. Fifty per address is high enough
 * that a shared office gateway is not taken out by one colleague's bad morning,
 * and low enough that an address running a dictionary stops mattering within a
 * quarter of an hour.
 *
 * ## Why the delay is capped
 *
 * Each delayed request holds a PHP-FPM worker for the duration. A delay that
 * kept doubling would let an attacker spend a pool of workers for the price of
 * some connections — a rate limiter that becomes a denial of service is worse
 * than none. One second is the ceiling, and past the lock threshold the answer
 * is an immediate `429`, which costs nothing to produce.
 *
 * ## A lockout does not confirm anything
 *
 * The counter is keyed on the name that was *sent*, existing or not, and the
 * `429` is identical either way. Otherwise the limiter would answer the
 * question the sign-in endpoint is careful not to answer.
 *
 * ## Behind a reverse proxy
 *
 * Every request then carries the proxy's address and the per-address counter
 * degenerates to a global one. `X-Forwarded-For` is deliberately not read: the
 * header is attacker-controlled unless a trusted proxy overwrites it, and a
 * limiter keyed on attacker-controlled input is worse than one key too coarse.
 * Open point O-6.
 */
final readonly class RateLimiter
{
    /** The sliding window every count is taken over. */
    public const int WINDOW_SECONDS = 900;

    /** Failures for one user name before the answers start getting slower. */
    public const int DELAY_FROM = 3;

    /** Failures for one user name before sign-in is refused outright. */
    public const int USERNAME_LOCK_AT = 10;

    /** Failures from one address before sign-in is refused outright. */
    public const int CLIENT_LOCK_AT = 50;

    /** Ceiling on the growing delay. See the class comment. */
    public const int MAX_DELAY_MILLISECONDS = 1000;

    public function __construct(
        private LoginAttemptLog $attempts,
        private Clock $clock,
        private Sleeper $sleeper,
    ) {
    }

    /**
     * What to do with this attempt, before the password is checked.
     */
    public function check(string $username, string $clientIp): RateLimitDecision
    {
        $since = $this->windowStart();

        $forUser = $this->attempts->countForUsername($username, $since);

        if ($forUser >= self::USERNAME_LOCK_AT) {
            return RateLimitDecision::refused(self::WINDOW_SECONDS, 'username');
        }

        if ($clientIp !== '') {
            $forClient = $this->attempts->countForClient($clientIp, $since);

            if ($forClient >= self::CLIENT_LOCK_AT) {
                return RateLimitDecision::refused(self::WINDOW_SECONDS, 'client');
            }
        }

        return RateLimitDecision::allowed(self::delayFor($forUser));
    }

    /**
     * Apply whatever waiting the decision calls for.
     *
     * Separate from {@see check()} so that the decision stays a value and the
     * one place that blocks is visible — and so that a test can assert the
     * duration without spending it.
     */
    public function applyDelay(RateLimitDecision $decision): void
    {
        $this->sleeper->sleepMilliseconds($decision->delayMilliseconds);
    }

    public function recordFailure(string $username, string $clientIp): void
    {
        $this->attempts->recordFailure($username, $clientIp, $this->clock->now());
    }

    /**
     * A correct password clears this user name's counter — see
     * {@see LoginAttemptLog::clearUsername()} for why the address keeps its.
     */
    public function recordSuccess(string $username): void
    {
        $this->attempts->clearUsername($username);
    }

    /**
     * Doubling from an eighth of a second, capped.
     *
     * The first two failures are answered at full speed: making the *first*
     * attempt slow would tax every ordinary sign-in for nothing, since an
     * attacker's first guess is no more valuable than a legitimate user's.
     */
    private static function delayFor(int $failures): int
    {
        if ($failures < self::DELAY_FROM) {
            return 0;
        }

        $steps = min($failures - self::DELAY_FROM + 1, 10);

        return min(125 * (2 ** $steps), self::MAX_DELAY_MILLISECONDS);
    }

    private function windowStart(): \DateTimeImmutable
    {
        $now = $this->clock->now();

        return $now->setTimestamp($now->getTimestamp() - self::WINDOW_SECONDS);
    }
}
