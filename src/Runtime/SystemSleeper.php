<?php

declare(strict_types=1);

namespace Mailadmin\Runtime;

/**
 * Actually waits.
 *
 * A non-positive duration returns at once rather than being passed to
 * `usleep()`, which would be a negative-argument error on some platforms and a
 * no-op on others.
 */
final class SystemSleeper implements Sleeper
{
    public function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        usleep($milliseconds * 1000);
    }
}
