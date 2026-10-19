<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

use Mailadmin\Domain\Access\ActorContext;

/**
 * `GET /maintenance/health`: every probe run now, with error codes and
 * durations — the detail the public `/health` deliberately leaves out (U-121).
 */
final readonly class GetHealthDetails
{
    public function __construct(private HealthMonitor $monitor)
    {
    }

    public function handle(ActorContext $actor): HealthReport
    {
        return $this->monitor->fresh();
    }
}
