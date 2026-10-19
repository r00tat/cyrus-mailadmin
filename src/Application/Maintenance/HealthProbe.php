<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

/**
 * One dependency check.
 *
 * **Never throws.** A probe that fails answers `error` with a code; a health
 * endpoint that turned a dead dependency into a `500` would report the
 * application as broken when it is merely telling the truth (A6, test case
 * "Cyrus switched off").
 */
interface HealthProbe
{
    public function component(): HealthComponent;

    public function probe(): ProbeResult;
}
