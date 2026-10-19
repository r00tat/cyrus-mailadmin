<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Health;

use Closure;
use Mailadmin\Application\Maintenance\HealthComponent;
use Mailadmin\Application\Maintenance\HealthProbe;
use Mailadmin\Application\Maintenance\ProbeResult;
use Mailadmin\Domain\Mailstore\MailstoreFailure;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use Throwable;

/**
 * Runs one check, measures it, and turns every failure into `error` with a
 * stable code (A6, U-121).
 *
 * The check is a closure that builds its own client. That is what makes a
 * configuration error — a missing host, an unreadable CA file — an `error` for
 * this one component instead of a container failure that takes the whole
 * health endpoint down with it.
 */
final readonly class TimedProbe implements HealthProbe
{
    /** What a failure without a code of its own is reported as. */
    public const string UNAVAILABLE = 'request.service_unavailable';

    /**
     * @param Closure(): void $check throws when the dependency is not usable
     */
    public function __construct(
        private HealthComponent $component,
        private Closure $check,
    ) {
    }

    public function component(): HealthComponent
    {
        return $this->component;
    }

    public function probe(): ProbeResult
    {
        $started = hrtime(true);

        try {
            ($this->check)();
        } catch (MailstoreFailure $failure) {
            return ProbeResult::error($this->component, $failure->errorCode(), self::since($started));
        } catch (PersistenceError $failure) {
            return ProbeResult::error($this->component, $failure->errorCode(), self::since($started));
        } catch (Throwable) {
            return ProbeResult::error($this->component, self::UNAVAILABLE, self::since($started));
        }

        return ProbeResult::ok($this->component, self::since($started));
    }

    private static function since(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
