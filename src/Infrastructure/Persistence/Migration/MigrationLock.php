<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence\Migration;

use Mailadmin\Infrastructure\Persistence\Connection;
use PDO;
use Throwable;

/**
 * The advisory lock that makes two simultaneous migration runs impossible
 * (architecture 10.2, F3 open point 3).
 *
 * `GET_LOCK()` is the answer the package document suggests, and it is the right
 * one: it needs no table — which matters, because the first run has no tables of
 * ours at all — it is released when the connection drops, so a killed deployment
 * does not leave a stuck lock, and it is visible to every client of the same
 * server.
 *
 * **The name is scoped to the database.** `GET_LOCK` is server-wide, so two
 * installations sharing one MariaDB would otherwise block each other for no
 * reason.
 *
 * **On timeout nothing happens at all.** The caller is told that someone else
 * holds the lock and exits without touching the schema. Waiting longer would be
 * worse: the other run either finishes, in which case the next invocation has
 * nothing left to do, or it is stuck, in which case a second one must not join
 * it.
 *
 * On a driver without `GET_LOCK` — SQLite, in the tests — locking is skipped and
 * {@see isSupported()} says so. The migrations themselves are MySQL DDL, so this
 * only affects the unit tests, which run one migrator at a time by construction.
 */
final readonly class MigrationLock
{
    public const string PREFIX = 'mailadmin_migration';

    /** Environment variable that overrides the wait. */
    public const string TIMEOUT = 'MAILADMIN_MIGRATION_LOCK_TIMEOUT';

    /** Long enough for a small migration to finish, short enough not to hang a deployment. */
    public const int DEFAULT_TIMEOUT_SECONDS = 10;

    public function __construct(
        private Connection $connection,
        private string $name,
        private int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
    }

    public static function forDatabase(
        Connection $connection,
        ?string $databaseName,
        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ): self {
        $name = $databaseName === null || $databaseName === ''
            ? self::PREFIX
            : self::PREFIX . ':' . $databaseName;

        // MySQL rejects a lock name longer than 64 characters.
        return new self($connection, substr($name, 0, 64), $timeoutSeconds);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isSupported(): bool
    {
        /** @var mixed $driver */
        $driver = $this->connection->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);

        return $driver === 'mysql';
    }

    /**
     * Run `$work` while holding the lock, and release it afterwards — including
     * when `$work` throws.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws MigrationError when the lock cannot be taken within the timeout
     * @throws Throwable      whatever `$work` threw
     */
    public function around(callable $work): mixed
    {
        if (!$this->isSupported()) {
            return $work();
        }

        $this->acquire();

        try {
            return $work();
        } finally {
            $this->release();
        }
    }

    /**
     * @throws MigrationError
     */
    private function acquire(): void
    {
        try {
            /** @var mixed $result */
            $result = $this->connection->selectValue(
                'SELECT GET_LOCK(:name, :timeout)',
                ['name' => $this->name, 'timeout' => $this->timeoutSeconds],
            );
        } catch (Throwable $error) {
            throw MigrationError::lockFailed($this->name, $error);
        }

        // 1 = acquired, 0 = timed out, NULL = error.
        if (!is_numeric($result) || (int) $result !== 1) {
            throw MigrationError::lockUnavailable($this->name, $this->timeoutSeconds);
        }
    }

    private function release(): void
    {
        try {
            $this->connection->selectValue(
                'SELECT RELEASE_LOCK(:name)',
                ['name' => $this->name],
            );
        } catch (Throwable) {
            // The lock disappears with the connection anyway. Failing to release
            // must not replace the exception the caller is already dealing with.
        }
    }
}
