<?php

declare(strict_types=1);

/**
 * Container definitions for A6 — reconciliation, repair queue, health.
 *
 * One file per package (U-5). The use cases, handlers and the responder are
 * autowired; what is here is what autowiring cannot decide.
 *
 * **The repair queue gets a connection of its own** (U-117). An entry is
 * written when an operation failed, often from inside a transaction about to be
 * rolled back; on the shared connection it would roll back too. The second
 * connection comes from the same source and opens only when it is used. One
 * repository instance serves both ports — the append-only `RepairQueue` the
 * other packages record into, and the `RepairLedger` A6 reads and closes.
 *
 * **Each health probe builds its client inside the probe.** A missing setting
 * or an unreadable CA file then becomes `error` for that one component instead
 * of a container failure that takes `/health` down with it — exactly when it is
 * needed most.
 *
 * The in-memory doubles are not wired, for the reason U-37 gives.
 */

use Mailadmin\Application\Audit\AuditRequestContext;
use Mailadmin\Application\Maintenance\HealthComponent;
use Mailadmin\Application\Maintenance\HealthProbes;
use Mailadmin\Application\Maintenance\HealthReportCache;
use Mailadmin\Application\Maintenance\MaintenanceInventory;
use Mailadmin\Application\Maintenance\RepairLedger;
use Mailadmin\Domain\Repair\RepairQueue;
use Mailadmin\Infrastructure\Health\FileHealthReportCache;
use Mailadmin\Infrastructure\Health\TimedProbe;
use Mailadmin\Infrastructure\Mailstore\Imap\ConnectionPool;
use Mailadmin\Infrastructure\Mailstore\Imap\ImapCommand;
use Mailadmin\Infrastructure\Mailstore\Sieve\ManageSieveConnectionPool;
use Mailadmin\Infrastructure\Persistence\Connection;
use Mailadmin\Infrastructure\Persistence\ConnectionSource;
use Mailadmin\Infrastructure\Persistence\RepairQueueRepository;
use Mailadmin\Infrastructure\Persistence\SqlMaintenanceInventory;
use Mailadmin\Infrastructure\Repair\LoggingRepairQueue;
use Mailadmin\Runtime\CacheDirectory;
use Mailadmin\Runtime\Clock;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

return [
    // ------------------------------------------------------------------
    // The repair queue — the table, with the critical log line as fallback.
    // ------------------------------------------------------------------
    RepairQueueRepository::class => static fn (
        ConnectionSource $source,
        Clock $clock,
        AuditRequestContext $context,
        LoggerInterface $logger,
    ): RepairQueueRepository => new RepairQueueRepository(
        new Connection($source),
        $clock,
        $context,
        new LoggingRepairQueue($logger),
    ),

    RepairQueue::class => static fn (RepairQueueRepository $repository): RepairQueue => $repository,

    RepairLedger::class => static fn (RepairQueueRepository $repository): RepairLedger => $repository,

    // ------------------------------------------------------------------
    // What the reconciliation compares Cyrus against.
    // ------------------------------------------------------------------
    MaintenanceInventory::class => static fn (Connection $connection): SqlMaintenanceInventory
        => new SqlMaintenanceInventory($connection),

    // ------------------------------------------------------------------
    // Health: a real query, a real administrator login at IMAP, and a
    // verified TLS session at ManageSieve (U-121).
    // ------------------------------------------------------------------
    HealthProbes::class => static fn (ContainerInterface $container): HealthProbes => new HealthProbes(
        new TimedProbe(HealthComponent::Database, static function () use ($container): void {
            $connection = $container->get(Connection::class);
            assert($connection instanceof Connection);
            $connection->selectValue('SELECT 1');
        }),
        new TimedProbe(HealthComponent::Imap, static function () use ($container): void {
            $pool = $container->get(ConnectionPool::class);
            assert($pool instanceof ConnectionPool);
            $pool->administrative()->execute(ImapCommand::of('NOOP'));
        }),
        new TimedProbe(HealthComponent::ManageSieve, static function () use ($container): void {
            $pool = $container->get(ManageSieveConnectionPool::class);
            assert($pool instanceof ManageSieveConnectionPool);
            // Connect and STARTTLS only: timsieved refuses the administrator's
            // own session, and the credentials are proved by the IMAP probe.
            $pool->probe();
        }),
    ),

    HealthReportCache::class => static fn (
        CacheDirectory $directory,
        Clock $clock,
        LoggerInterface $logger,
    ): FileHealthReportCache => new FileHealthReportCache($directory, $clock, logger: $logger),
];
