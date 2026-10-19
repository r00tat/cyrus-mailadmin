<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Mailadmin\Runtime\Clock;

/**
 * A clock that only moves when a test moves it.
 *
 * Everything S2 does is about durations — sixty minutes, twelve hours, a
 * fifteen-minute rate-limit window — and none of it can be tested by a suite
 * that waits. Advancing time explicitly also makes the assertion say what it
 * means: `$clock->advance(3601)` reads as "an hour and a second later", which
 * `sleep(3601)` never would.
 *
 * Always UTC, like {@see \Mailadmin\Runtime\SystemClock} (U-14).
 */
final class FrozenClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $moment = '2026-01-01 12:00:00')
    {
        $this->now = new DateTimeImmutable($moment, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): self
    {
        $this->now = $this->now->setTimestamp($this->now->getTimestamp() + $seconds);

        return $this;
    }
}
