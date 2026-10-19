<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\TestEnvironment;

/**
 * Skip, do not fail, when the Docker environment is not running.
 *
 * A test suite that goes red on a laptop without Docker stops being run, and
 * then the unit tests stop being run with it. The skip message says exactly how
 * to get the environment, so a skip is a pointer rather than a shrug.
 */
trait RequiresTestEnvironment
{
    protected function requireTestEnvironment(): void
    {
        $reason = TestEnvironment::unavailableReason();

        if ($reason !== null) {
            self::markTestSkipped($reason);
        }
    }

    protected function requireFixtures(): void
    {
        $this->requireTestEnvironment();

        if (!TestEnvironment::fixturesLoaded()) {
            self::markTestSkipped(
                'The test environment is running but holds no fixtures. Run `bin/test-env seed`.',
            );
        }
    }
}
