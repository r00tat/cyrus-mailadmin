<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

/**
 * Keeps the public health answer for a few seconds (U-121).
 *
 * `/health` is reachable without a token and each round logs in to Cyrus and opens a TLS session at ManageSieve.
 * Without a cache every unauthenticated request would cost an administrator login
 * and two TLS handshakes; with it, a burst costs that once per interval, whoever sends it.
 * That includes a burst of concurrent requests: an implementation runs at most one
 * round at a time and answers the others from what it has (U-123).
 */
interface HealthReportCache
{
    /**
     * The cached report if it is fresh enough, otherwise `$compute()`'s, which
     * is then kept.
     *
     * @param callable(): HealthReport $compute
     */
    public function remember(callable $compute): HealthReport;
}
