<?php

declare(strict_types=1);

/**
 * Container definitions for F3 — database access, migrator, schema guard.
 *
 * One file per package (U-5). Only what autowiring cannot work out is here: the
 * configuration values, and the two objects that need a path.
 *
 * Note what is **not** wired: {@see SchemaVersionMiddleware} is built here but
 * is not part of the middleware chain. The chain lives in `config/di/core.php`,
 * which belongs to F1/F2; adding the guard is one argument there. Until that
 * happens the guard exists, is tested, and is reachable through the container —
 * but no request passes through it.
 */

use Mailadmin\Config\Config;
use Mailadmin\Http\Problem\ProblemResponseFactory;
use Mailadmin\Infrastructure\Persistence\Connection;
use Mailadmin\Infrastructure\Persistence\ConnectionFactory;
use Mailadmin\Infrastructure\Persistence\ConnectionSource;
use Mailadmin\Infrastructure\Persistence\DatabaseConfiguration;
use Mailadmin\Infrastructure\Persistence\Guard\SchemaGuard;
use Mailadmin\Infrastructure\Persistence\Guard\SchemaVersionMiddleware;
use Mailadmin\Infrastructure\Persistence\Migration\MigrationLoader;
use Mailadmin\Infrastructure\Persistence\Migration\MigrationLock;
use Mailadmin\Infrastructure\Persistence\Migration\Migrator;
use Mailadmin\Infrastructure\Persistence\Migration\SchemaVersionTable;
use Mailadmin\Runtime\Paths;
use Psr\Log\LoggerInterface;

return [
    // ------------------------------------------------------------------
    // Connection. Read once, opened on first use — a request that touches no
    // table opens no socket.
    // ------------------------------------------------------------------
    DatabaseConfiguration::class => static fn (Config $config): DatabaseConfiguration
        => DatabaseConfiguration::fromConfig($config),

    ConnectionSource::class => static fn (ConnectionFactory $factory): ConnectionFactory => $factory,

    Connection::class => static fn (ConnectionSource $source): Connection => new Connection($source),

    // ------------------------------------------------------------------
    // Migrator. The same objects serve `bin/migrate.php` and the guard, so the
    // command and the check cannot disagree about what "pending" means.
    // ------------------------------------------------------------------
    MigrationLoader::class => static fn (Paths $paths): MigrationLoader
        => new MigrationLoader($paths->join('migrations')),

    MigrationLock::class => static fn (
        Connection $connection,
        DatabaseConfiguration $database,
        Config $config,
    ): MigrationLock => MigrationLock::forDatabase(
        $connection,
        $database->databaseName(),
        $config->intOrDefault(MigrationLock::TIMEOUT, MigrationLock::DEFAULT_TIMEOUT_SECONDS),
    ),

    Migrator::class => static fn (
        Connection $connection,
        MigrationLoader $loader,
        SchemaVersionTable $versions,
        MigrationLock $lock,
        LoggerInterface $logger,
    ): Migrator => new Migrator($connection, $loader, $versions, $lock, $logger),

    // ------------------------------------------------------------------
    // The guard. Checks; migrates only when explicitly told to (U-6 note in
    // architecture 10.2, F3 open point 6).
    // ------------------------------------------------------------------
    SchemaGuard::class => static fn (
        SchemaVersionTable $versions,
        MigrationLoader $loader,
        Migrator $migrator,
        Config $config,
        LoggerInterface $logger,
    ): SchemaGuard => new SchemaGuard(
        versions: $versions,
        loader: $loader,
        migrator: $migrator,
        autoMigrate: $config->boolOrDefault(SchemaGuard::AUTO_MIGRATE, false),
        logger: $logger,
    ),

    SchemaVersionMiddleware::class => static fn (
        SchemaGuard $guard,
        ProblemResponseFactory $problems,
        LoggerInterface $logger,
    ): SchemaVersionMiddleware => new SchemaVersionMiddleware($guard, $problems, $logger),
];
