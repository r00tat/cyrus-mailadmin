<?php

declare(strict_types=1);

namespace Mailadmin\Runtime;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The machine's clock, in UTC.
 *
 * The zone is not configurable and not read from the environment. `date.timezone`
 * in `php.ini` is one of the settings an operator changes for unrelated reasons,
 * and a token whose `exp` silently moved by two hours is a bug that looks like a
 * clock-skew problem for an afternoon.
 *
 * Formatting for a locale is the frontend's business (architecture 11.4); the
 * backend deals in UTC and says so in the contract.
 */
final class SystemClock implements Clock
{
    private readonly DateTimeZone $utc;

    public function __construct()
    {
        $this->utc = new DateTimeZone('UTC');
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->utc);
    }
}
