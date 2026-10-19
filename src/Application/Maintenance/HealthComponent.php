<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

/**
 * The dependencies `/health` probes. Generic names that follow from the
 * architecture, never from an installation (`components/health.yaml`).
 */
enum HealthComponent: string
{
    case Database = 'database';
    case Imap = 'imap';
    case ManageSieve = 'managesieve';
}
