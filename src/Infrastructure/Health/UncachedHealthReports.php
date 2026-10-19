<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Health;

use Mailadmin\Application\Maintenance\HealthReport;
use Mailadmin\Application\Maintenance\HealthReportCache;

/**
 * No cache: every call runs the probes. For tests, where a report from the
 * previous test would be the wrong answer.
 */
final readonly class UncachedHealthReports implements HealthReportCache
{
    public function remember(callable $compute): HealthReport
    {
        return $compute();
    }
}
