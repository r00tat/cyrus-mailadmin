<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence\Guard;

use Mailadmin\Infrastructure\Persistence\Migration\MigrationLoader;
use Mailadmin\Infrastructure\Persistence\Migration\Migrator;
use Mailadmin\Infrastructure\Persistence\Migration\SchemaVersionTable;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The application's half of architecture 10.2: **check, do not migrate.**
 *
 * ```text
 * 1. bin/migrate.php applies the migrations, under the lock. That is the
 *    deployment's job.
 * 2. The web application compares the version and refuses service when it is
 *    behind. It does not migrate.
 * 3. MAILADMIN_AUTO_MIGRATE, off by default, lets a small installation do step 1
 *    on first access — under the same lock.
 * ```
 *
 * ## The cost of the check
 *
 * One `SELECT version FROM mailadmin_schema_version` and a `glob` of the
 * migration directory, once per request, and only when something actually
 * reaches for the database — the guard is invoked from a middleware, but the
 * comparison is lazy and its result is remembered for the rest of the request.
 * That is a deliberate trade: it is cheap enough not to matter and it is
 * *correct*, which a cached answer in `var/cache` would not be — the cache
 * survives a deployment that did not run the migrator, which is the exact
 * situation this guard is for.
 *
 * ## MAILADMIN_AUTO_MIGRATE (F3 open point 6)
 *
 * Confirmed off by default. When it is on, the guard delegates to
 * {@see Migrator::migrate()}, which takes the same `GET_LOCK` as the command
 * line does — so two requests arriving together produce one migration and one
 * short wait, not two half-migrations. If the lock cannot be taken within its
 * timeout the request is refused exactly as if migration were off: the other
 * process is doing the work, and this request must not be served against a
 * schema that is halfway there.
 */
final class SchemaGuard
{
    /**
     * Environment variable for step 3. Off unless an operator says otherwise —
     * O-4 leaves it open whether it appears in the shipped `.env.example` at all.
     */
    public const string AUTO_MIGRATE = 'MAILADMIN_AUTO_MIGRATE';

    /** Remembered for the rest of the request once the schema has been found current. */
    private bool $verified = false;

    public function __construct(
        private readonly SchemaVersionTable $versions,
        private readonly MigrationLoader $loader,
        private readonly Migrator $migrator,
        private readonly bool $autoMigrate = false,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @throws SchemaOutdated   when the schema is behind and auto-migration is off or blocked
     * @throws PersistenceError when the database cannot be reached at all
     */
    public function verify(): void
    {
        if ($this->verified) {
            return;
        }

        $pending = $this->pending();

        if ($pending === []) {
            $this->verified = true;

            return;
        }

        if (!$this->autoMigrate) {
            throw SchemaOutdated::behind($pending, $this->versions->exists());
        }

        $this->logger->warning('Schema is behind; migrating because MAILADMIN_AUTO_MIGRATE is on', [
            'pending' => count($pending),
        ]);

        $this->migrator->migrate(Migrator::BY_AUTO);

        // Verified by looking again rather than by assumption: the migration may
        // have been a no-op because another process got there first, and either
        // way the state has to be true afterwards.
        $remaining = $this->pending();

        if ($remaining !== []) {
            throw SchemaOutdated::behind($remaining, $this->versions->exists());
        }

        $this->verified = true;
    }

    /**
     * `true` when the application may serve requests; no exception either way.
     */
    public function isSatisfied(): bool
    {
        try {
            $this->verify();

            return true;
        } catch (PersistenceError) {
            return false;
        }
    }

    /**
     * @return list<int> the numbers this build ships that the database has not applied
     *
     * @throws PersistenceError
     */
    private function pending(): array
    {
        $available = $this->loader->numbers();
        $applied = $this->versions->appliedNumbersOrNull() ?? [];

        return array_values(array_diff($available, $applied));
    }
}
