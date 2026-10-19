<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Infrastructure\Security;

use Mailadmin\Infrastructure\Persistence\InMemoryLoginAttemptLog;
use Mailadmin\Infrastructure\Security\RateLimitDecision;
use Mailadmin\Infrastructure\Security\RateLimiter;
use Mailadmin\Tests\Support\FrozenClock;
use Mailadmin\Tests\Support\RecordingSleeper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The sign-in rate limiter (architecture 5.6).
 *
 * Two properties are worth more than the exact numbers: that a lockout says
 * nothing about whether the account exists, and that the delay has a ceiling.
 * Without the ceiling the limiter becomes a way of spending the PHP-FPM worker
 * pool, which would make it a denial-of-service tool rather than a defence
 * against one.
 */
#[CoversClass(RateLimiter::class)]
#[CoversClass(RateLimitDecision::class)]
#[CoversClass(InMemoryLoginAttemptLog::class)]
final class RateLimiterTest extends TestCase
{
    private const string IP = '203.0.113.7';

    private FrozenClock $clock;
    private RecordingSleeper $sleeper;
    private InMemoryLoginAttemptLog $attempts;
    private RateLimiter $limiter;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->sleeper = new RecordingSleeper();
        $this->attempts = new InMemoryLoginAttemptLog();
        $this->limiter = new RateLimiter($this->attempts, $this->clock, $this->sleeper);
    }

    public function testTheFirstAttemptsAreAnsweredAtFullSpeed(): void
    {
        $decision = $this->limiter->check('anna', self::IP);

        self::assertTrue($decision->allowed);
        self::assertSame(0, $decision->delayMilliseconds);
    }

    /**
     * Delaying the very first attempt would tax every ordinary sign-in for
     * nothing — an attacker's first guess is worth no more than a legitimate
     * user's.
     */
    public function testTheDelayStartsOnlyAfterAFewFailures(): void
    {
        $this->failTimes('anna', RateLimiter::DELAY_FROM - 1);

        self::assertSame(0, $this->limiter->check('anna', self::IP)->delayMilliseconds);

        $this->failTimes('anna', 1);

        self::assertGreaterThan(0, $this->limiter->check('anna', self::IP)->delayMilliseconds);
    }

    public function testTheDelayGrowsButNeverPastTheCeiling(): void
    {
        $seen = [];

        for ($failures = 0; $failures < RateLimiter::USERNAME_LOCK_AT; ++$failures) {
            $seen[] = $this->limiter->check('anna', self::IP)->delayMilliseconds;
            $this->failTimes('anna', 1);
        }

        self::assertSame(RateLimiter::MAX_DELAY_MILLISECONDS, max($seen));
        self::assertSame($seen, self::nonDecreasing($seen), 'The delay must never shrink as failures pile up.');
    }

    public function testTheDelayIsAppliedThroughTheSleeperAndNowhereElse(): void
    {
        $this->failTimes('anna', RateLimiter::DELAY_FROM);

        $decision = $this->limiter->check('anna', self::IP);
        $this->limiter->applyDelay($decision);

        self::assertSame([$decision->delayMilliseconds], $this->sleeper->requested());
    }

    public function testTheAccountIsLockedAfterTheThreshold(): void
    {
        $this->failTimes('anna', RateLimiter::USERNAME_LOCK_AT);

        $decision = $this->limiter->check('anna', self::IP);

        self::assertFalse($decision->allowed);
        self::assertSame('username', $decision->reason);
        self::assertGreaterThan(0, $decision->retryAfterSeconds);
    }

    /**
     * The counter is keyed on the name that was *sent*. A name nobody has must
     * lock out exactly like one somebody has, or the limiter answers the
     * question the sign-in endpoint refuses to answer.
     */
    public function testANameThatDoesNotExistLocksOutIdentically(): void
    {
        $this->failTimes('anna', RateLimiter::USERNAME_LOCK_AT);
        $this->failTimes('nobody-has-this-name', RateLimiter::USERNAME_LOCK_AT);

        $forExisting = $this->limiter->check('anna', self::IP);
        $forUnknown = $this->limiter->check('nobody-has-this-name', self::IP);

        self::assertSame($forExisting->allowed, $forUnknown->allowed);
        self::assertSame($forExisting->reason, $forUnknown->reason);
        self::assertSame($forExisting->retryAfterSeconds, $forUnknown->retryAfterSeconds);
    }

    /**
     * One person mistyping their password must not lock out everyone behind the
     * same NAT gateway, which is why the per-address threshold is far higher
     * than the per-name one.
     */
    public function testOneAddressIsNotLockedOutByASingleAccountsFailures(): void
    {
        $this->failTimes('anna', RateLimiter::USERNAME_LOCK_AT);

        self::assertTrue($this->limiter->check('bruno', self::IP)->allowed);
    }

    public function testAnAddressSpreadingAttemptsAcrossNamesIsStillStopped(): void
    {
        for ($i = 0; $i < RateLimiter::CLIENT_LOCK_AT; ++$i) {
            $this->limiter->recordFailure('name-' . $i, self::IP);
        }

        $decision = $this->limiter->check('someone-new', self::IP);

        self::assertFalse($decision->allowed);
        self::assertSame('client', $decision->reason);
    }

    /**
     * Behind a reverse proxy the address is unknown or useless (O-6). Counting
     * an empty address would put the whole world in one bucket.
     */
    public function testAnUnknownAddressIsNotCounted(): void
    {
        for ($i = 0; $i < RateLimiter::CLIENT_LOCK_AT * 2; ++$i) {
            $this->limiter->recordFailure('name-' . $i, '');
        }

        self::assertTrue($this->limiter->check('someone-new', '')->allowed);
    }

    public function testFailuresLeaveTheWindow(): void
    {
        $this->failTimes('anna', RateLimiter::USERNAME_LOCK_AT);

        self::assertFalse($this->limiter->check('anna', self::IP)->allowed);

        $this->clock->advance(RateLimiter::WINDOW_SECONDS + 1);

        self::assertTrue($this->limiter->check('anna', self::IP)->allowed);
    }

    /**
     * A correct password clears the name's counter, so somebody who mistypes
     * twice a week never accumulates their way into a lockout.
     */
    public function testASuccessfulSignInForgetsTheFailures(): void
    {
        $this->failTimes('anna', RateLimiter::USERNAME_LOCK_AT - 1);

        $this->limiter->recordSuccess('anna');

        self::assertSame(0, $this->limiter->check('anna', self::IP)->delayMilliseconds);
    }

    /**
     * One correct password does not clear the address.
     *
     * It does drop that one name's contribution — a row carries both keys, so
     * there is no state in which a failure is forgotten for the name and
     * remembered for the address. What must survive is everything the address
     * accumulated under *other* names, and that is what this asserts: one
     * success out of a hundred failures leaves the address locked.
     */
    public function testASuccessfulSignInDoesNotClearWhatTheAddressDidUnderOtherNames(): void
    {
        for ($i = 0; $i < RateLimiter::CLIENT_LOCK_AT * 2; ++$i) {
            $this->limiter->recordFailure('name-' . $i, self::IP);
        }

        $this->limiter->recordSuccess('name-0');

        self::assertFalse($this->limiter->check('someone-new', self::IP)->allowed);
    }

    private function failTimes(string $username, int $times): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $this->limiter->recordFailure($username, self::IP);
        }
    }

    /**
     * @param list<int> $values
     *
     * @return list<int>
     */
    private static function nonDecreasing(array $values): array
    {
        $result = [];
        $highest = 0;

        foreach ($values as $value) {
            $highest = max($highest, $value);
            $result[] = $highest;
        }

        return $result;
    }
}
