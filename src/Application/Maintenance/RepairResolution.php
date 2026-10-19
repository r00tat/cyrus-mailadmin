<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

/**
 * How a resolved repair-queue entry was resolved.
 *
 * `retried` — the application finished the step itself. `dismissed` — an
 * administrator says it was handled some other way, or needs no handling. The
 * difference matters to whoever reads the queue later: only the first is
 * evidence that the state was actually repaired.
 */
enum RepairResolution: string
{
    case Retried = 'retried';
    case Dismissed = 'dismissed';
}
