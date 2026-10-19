<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence\Migration;

use Mailadmin\Infrastructure\Persistence\Connection;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Runs the pending migrations (architecture 10.2).
 *
 * The whole of it is about a hundred lines because the hard parts live
 * elsewhere: {@see MigrationLoader} finds and orders the files,
 * {@see AdditiveOnlyRule} decides whether a file may run at all, and
 * {@see MigrationLock} makes sure only one process does this at a time. What is
 * left here is the sequence.
 *
 * ## Why there is no transaction around a migration
 *
 * MySQL and MariaDB commit implicitly before and after every DDL statement.
 * `BEGIN … CREATE TABLE … ROLLBACK` does not undo the table; it only looks as
 * if it might, which is worse than not trying. So a migration that fails
 * halfway leaves what ran in place and is **not recorded** — the next run
 * repeats it from the first statement. Everything therefore has to be
 * idempotent, and {@see AdditiveOnlyRule} enforces it rather than asking for it.
 *
 * ## Why there is no rollback
 *
 * F3 open point 5, answered as the document proposes: **no.** Migrations are
 * forward-only and the way back is the dump. A `down` migration on a schema
 * three other systems read would have to be additive as well to be safe, which
 * makes it not a rollback.
 *
 * ## What it does not do
 *
 * It is never called from a web request unless `MAILADMIN_AUTO_MIGRATE` is on.
 * The application checks the version and refuses service; migrating under load
 * is the concurrency problem the lock exists to avoid, not to invite.
 */
final readonly class Migrator
{
    /** Recorded in `applied_by`. No host name, no user name — it is not a log. */
    public const string BY_CLI = 'cli';
    public const string BY_AUTO = 'auto';

    public function __construct(
        private Connection $connection,
        private MigrationLoader $loader,
        private SchemaVersionTable $versions,
        private MigrationLock $lock,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * What is applied, what is pending, what has drifted.
     *
     * Reads the database and the migration directory; changes nothing.
     *
     * @throws PersistenceError
     */
    public function state(): SchemaState
    {
        $available = $this->loader->load();

        foreach ($available as $migration) {
            // Checked here as well as before running, so that `status` reports a
            // migration that would be refused instead of listing it as pending.
            AdditiveOnlyRule::enforce($migration);
        }

        return SchemaState::compare(
            $this->versions->exists(),
            $this->versions->readApplied(),
            $available,
        );
    }

    /**
     * Apply everything that is pending, under the lock.
     *
     * @return list<Migration> what actually ran, in order — empty when there was nothing to do
     *
     * @throws MigrationError
     * @throws PersistenceError
     */
    public function migrate(string $appliedBy = self::BY_CLI): array
    {
        return $this->lock->around(function () use ($appliedBy): array {
            // Deliberately re-read inside the lock. Between the caller's status
            // check and here, another process may have finished the very
            // migrations we were about to run.
            $state = $this->state();

            if ($state->isUpToDate()) {
                return [];
            }

            $applied = [];

            foreach ($state->pending as $migration) {
                $this->apply($migration, $appliedBy);
                $applied[] = $migration;
            }

            return $applied;
        });
    }

    /**
     * @throws MigrationError
     */
    private function apply(Migration $migration, string $appliedBy): void
    {
        AdditiveOnlyRule::enforce($migration);

        $this->logger->info('Applying migration', [
            'migration' => $migration->label(),
            'statements' => count($migration->statements),
        ]);

        $startedAt = hrtime(true);

        foreach ($migration->statements as $position => $statement) {
            try {
                // No parameters: a migration is DDL written by us, and there is
                // nothing in it that could come from a request.
                $this->connection->pdo()->exec($statement);
            } catch (Throwable $error) {
                $this->logger->error('Migration failed', [
                    'migration' => $migration->label(),
                    'statement_number' => $position + 1,
                ]);

                throw MigrationError::statementFailed(
                    $migration->fileName,
                    $position + 1,
                    $statement,
                    $error,
                );
            }
        }

        $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        $this->versions->record($migration, $durationMs, $appliedBy);

        $this->logger->info('Migration applied', [
            'migration' => $migration->label(),
            'duration_ms' => $durationMs,
        ]);
    }
}
