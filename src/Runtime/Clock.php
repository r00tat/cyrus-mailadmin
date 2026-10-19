<?php

declare(strict_types=1);

namespace Mailadmin\Runtime;

use DateTimeImmutable;

/**
 * What time it is.
 *
 * S2 is the first package that cannot be tested without controlling this. A
 * token that expires in sixty minutes, a refresh family that dies after twelve
 * hours and a lockout that lifts after fifteen — none of those can be checked by
 * a test that has to wait for them, and `sleep()` in a test suite is how a suite
 * becomes something nobody runs.
 *
 * **Always UTC** (U-14). Every timestamp the application stores or compares is
 * UTC, so a clock that could hand out a local time would be a way to get one
 * into the database by accident. {@see SystemClock} is the only implementation
 * that reads the machine, and it fixes the zone.
 *
 * Not `psr/clock`: the interface is these four lines, the test double has to be
 * written either way, and E-12 asks for a reason before a package is added.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
