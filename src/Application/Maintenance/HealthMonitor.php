<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

use Mailadmin\Runtime\Clock;

/**
 * Runs the probes (A6 open point 6, U-121).
 *
 * The depth is a real query for the database, a real administrator login and
 * `NOOP` for IMAP — an open port with a broken SASL setup is not a working mail
 * store — and a verified TLS session for ManageSieve, whose daemon refuses the
 * administrator's own session (measured against F5).
 */
final readonly class HealthMonitor
{
    public function __construct(
        private HealthProbes $probes,
        private HealthReportCache $cache,
        private Clock $clock,
    ) {
    }

    /**
     * For `/health`, without a token — possibly a few seconds old.
     */
    public function publicReport(): HealthReport
    {
        return $this->cache->remember($this->fresh(...));
    }

    /**
     * Every probe, now.
     */
    public function fresh(): HealthReport
    {
        $checks = [];

        foreach ($this->probes->probes as $probe) {
            $checks[] = $probe->probe();
        }

        return new HealthReport($this->clock->now(), $checks);
    }
}
