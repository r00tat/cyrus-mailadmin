<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

/**
 * The probes an installation runs, in the order they are reported.
 */
final readonly class HealthProbes
{
    /** @var list<HealthProbe> */
    public array $probes;

    public function __construct(HealthProbe ...$probes)
    {
        $this->probes = array_values($probes);
    }
}
