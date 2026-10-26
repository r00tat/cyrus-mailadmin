<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Application\MailingList\ListDelivery;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Infrastructure\Persistence\PersistenceError;

/**
 * `GET /maintenance/health`: every probe run now, with error codes and
 * durations — the detail the public `/health` deliberately leaves out (U-121) —
 * and the list delivery settings.
 *
 * Whether the transport domain is also a managed domain needs the database,
 * so {@see ListDelivery} cannot check it itself. Creating and renaming a
 * domain refuse the name; only legacy data or a direct database edit makes
 * this true, and then the page says so.
 */
final readonly class GetHealthDetails
{
    public function __construct(
        private HealthMonitor $monitor,
        private ListDelivery $listDelivery,
        private DomainStore $domains,
    ) {
    }

    /**
     * Never throws for a database that is down: the details always answer,
     * and the `database` probe already reports the failure.
     */
    public function handle(ActorContext $actor): HealthDetails
    {
        try {
            $managed = $this->domains->exists($this->listDelivery->transportDomain);
        } catch (PersistenceError) {
            $managed = null;
        }

        return new HealthDetails($this->monitor->fresh(), $this->listDelivery, $managed);
    }
}
