<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence\Migration;

use DateTimeImmutable;
use DateTimeZone;
use Mailadmin\Infrastructure\Persistence\Connection;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use PDO;
use Throwable;

/**
 * Reads and writes `mailadmin_schema_version`.
 *
 * The table is created by migration 0001 like any other table — there is no
 * bootstrap path that creates it behind the migrator's back. Until it exists,
 * {@see readApplied()} returns nothing and {@see exists()} says why: an
 * unmigrated legacy database, which is what the first run finds (architecture
 * 10.2).
 */
final readonly class SchemaVersionTable
{
    public const string NAME = 'mailadmin_schema_version';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @throws PersistenceError when the connection itself fails
     */
    public function exists(): bool
    {
        $pdo = $this->connection->pdo();

        /** @var mixed $driver */
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $sql = match ($driver) {
            'mysql' => 'SELECT COUNT(*) FROM information_schema.tables '
                . 'WHERE table_schema = DATABASE() AND table_name = :name',
            'sqlite' => "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :name",
            default => null,
        };

        if ($sql === null) {
            return $this->probe();
        }

        /** @var mixed $count */
        $count = $this->connection->selectValue($sql, ['name' => self::NAME]);

        return is_numeric($count) && (int) $count > 0;
    }

    /**
     * @return list<AppliedMigration>
     *
     * @throws MigrationError when the table exists but cannot be read
     */
    public function readApplied(): array
    {
        if (!$this->exists()) {
            return [];
        }

        try {
            $rows = $this->connection->select(sprintf(
                'SELECT version, name, checksum, applied_at, duration_ms FROM %s ORDER BY version',
                self::NAME,
            ));
        } catch (Throwable $error) {
            throw MigrationError::versionTableUnreadable($error);
        }

        return array_map(AppliedMigration::fromRow(...), $rows);
    }

    /**
     * Record a migration as applied.
     *
     * `INSERT IGNORE` rather than `INSERT`: the run is already serialised by the
     * lock, but a duplicate here would mean losing the whole run over a row that
     * says something already true.
     *
     * The timestamp is formatted in PHP rather than left to `NOW()`. The session
     * is on `+00:00` (U-14), so both would agree — but a value computed here is
     * the same value the log line carries, and there is no second clock to
     * reason about.
     *
     * @throws PersistenceError
     */
    public function record(Migration $migration, int $durationMs, string $appliedBy): void
    {
        $this->connection->execute(
            sprintf(
                'INSERT IGNORE INTO %s (version, name, checksum, applied_at, applied_by, duration_ms) '
                . 'VALUES (:version, :name, :checksum, :applied_at, :applied_by, :duration_ms)',
                self::NAME,
            ),
            [
                'version' => $migration->number,
                'name' => $migration->name,
                'checksum' => $migration->checksum,
                'applied_at' => self::now(),
                'applied_by' => $appliedBy,
                'duration_ms' => $durationMs,
            ],
        );
    }

    /**
     * The applied version numbers, in one query, or `null` when the table is not
     * there.
     *
     * Used by the guard, which runs on every request and must not spend two
     * round trips — one to `information_schema` and one to the table — on
     * something that is almost always fine. The price is that a permission
     * problem is indistinguishable from a missing table here; both end in a
     * refusal to serve, and `bin/migrate.php status` tells the two apart.
     *
     * @return list<int>|null
     */
    public function appliedNumbersOrNull(): ?array
    {
        try {
            $rows = $this->connection->select(
                sprintf('SELECT version FROM %s', self::NAME),
            );
        } catch (Throwable) {
            return null;
        }

        $numbers = [];
        foreach ($rows as $row) {
            if (is_numeric($row['version'] ?? null)) {
                $numbers[] = (int) $row['version'];
            }
        }

        sort($numbers);

        return $numbers;
    }

    public static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    /**
     * Last resort for a driver we do not know: ask for no rows and see whether
     * the server objects.
     */
    private function probe(): bool
    {
        try {
            $this->connection->select(sprintf('SELECT 1 FROM %s WHERE 1 = 0', self::NAME));

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
