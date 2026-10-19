<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * The single door to the database.
 *
 * Everything the application executes goes through one of the five methods
 * below, and every one of them prepares. There is no `query()` and no `exec()`
 * on this class: the way to run SQL is to hand over a statement with
 * placeholders and an array of values, which makes "no SQL from strings"
 * (`CLAUDE.md`, rule 2) a property of the interface rather than of everybody's
 * attention. Where a fragment genuinely has to be composed — `ORDER BY`,
 * `LIMIT` — it is composed by {@see Repository} from an allowlist, never from a
 * request value.
 *
 * ## Transactions
 *
 * {@see transactional()} is the only way to open one. It re-enters: a use case
 * that calls two repositories, each of which wraps its work, produces **one**
 * transaction, and the outermost call decides. An exception anywhere inside
 * marks the transaction rollback-only, so an inner `catch` cannot accidentally
 * commit half of an operation.
 *
 * What it deliberately does *not* do is span the mailstore. MySQL transactions
 * do not reach Cyrus; architecture 8.1 prescribes an order around the
 * transaction instead, and that order is the caller's business:
 *
 * ```php
 * $mailstore->createMailbox($identity);              // first, outside
 * $connection->transactional(static function () {    // then, atomically
 *     …
 * });
 * ```
 *
 * **A caveat that no code here can check:** the legacy tables were created as
 * MyISAM (`webcyradm/scripts/create_mysql.sql`). A transaction over a MyISAM
 * table commits nothing and rolls back nothing — silently. `bin/db-check.php`
 * reports the storage engine of every legacy table for exactly this reason.
 *
 * ## Connecting
 *
 * Lazily, on first use. A request that touches no table opens no socket, and a
 * database that is down produces one clear error at the moment something needs
 * it rather than at startup.
 */
final class Connection
{
    private ?PDO $pdo = null;

    private int $depth = 0;

    private bool $rollbackOnly = false;

    public function __construct(private readonly ConnectionSource $source)
    {
    }

    /**
     * The raw handle.
     *
     * Needed for DDL (the migrator), for `lastInsertId()` and for driver
     * introspection. **Not** a way around the prepare-only rule: a caller that
     * reaches for this to interpolate a value is doing the thing this class
     * exists to prevent.
     *
     * @throws PersistenceError when the connection cannot be established
     */
    public function pdo(): PDO
    {
        return $this->pdo ??= $this->source->connect();
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    public function describe(): string
    {
        return $this->source->describe();
    }

    /**
     * @param array<string, scalar|null>|list<scalar|null> $parameters
     *
     * @return list<array<string, mixed>>
     *
     * @throws PersistenceError
     */
    public function select(string $sql, array $parameters = []): array
    {
        $statement = $this->run($sql, $parameters);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * @param array<string, scalar|null>|list<scalar|null> $parameters
     *
     * @return array<string, mixed>|null
     *
     * @throws PersistenceError
     */
    public function selectOne(string $sql, array $parameters = []): ?array
    {
        $statement = $this->run($sql, $parameters);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * The first column of the first row, or `null` when there is no row.
     *
     * @param array<string, scalar|null>|list<scalar|null> $parameters
     *
     * @throws PersistenceError
     */
    public function selectValue(string $sql, array $parameters = []): mixed
    {
        $statement = $this->run($sql, $parameters);

        /** @var mixed $value */
        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * An `INSERT`, `UPDATE` or `DELETE`. Returns the number of affected rows.
     *
     * @param array<string, scalar|null>|list<scalar|null> $parameters
     *
     * @throws PersistenceError
     */
    public function execute(string $sql, array $parameters = []): int
    {
        return $this->run($sql, $parameters)->rowCount();
    }

    /**
     * Runs `$work` inside a transaction and returns whatever it returns.
     *
     * @template T
     *
     * @param callable(self): T $work
     *
     * @return T
     *
     * @throws PersistenceError
     * @throws Throwable whatever `$work` threw, after the rollback
     */
    public function transactional(callable $work): mixed
    {
        $this->begin();

        try {
            /** @var T $result */
            $result = $work($this);
        } catch (Throwable $error) {
            $this->rollbackOnly = true;
            $this->finish(commit: false);

            throw $error;
        }

        $this->finish(commit: true);

        return $result;
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }

    /**
     * The id of the last inserted row.
     *
     * The legacy tables have no auto-increment key — this is for the
     * `mailadmin_*` tables that do (S4's audit log, A6's repair queue).
     *
     * @throws PersistenceError
     */
    public function lastInsertId(): string
    {
        $id = $this->pdo()->lastInsertId();

        return $id === false ? '' : $id;
    }

    /**
     * @param array<string, scalar|null>|list<scalar|null> $parameters
     *
     * @throws PersistenceError
     */
    private function run(string $sql, array $parameters): PDOStatement
    {
        $pdo = $this->pdo();

        try {
            $statement = $pdo->prepare($sql);

            /** @var scalar|null $value */
            foreach ($parameters as $name => $value) {
                $statement->bindValue(
                    is_int($name) ? $name + 1 : ':' . ltrim($name, ':'),
                    $value,
                    self::parameterType($value),
                );
            }

            $statement->execute();
        } catch (PDOException $error) {
            // The values are not part of the message on purpose: one of them may
            // be a password hash, and U-28's masking cannot see inside a string.
            throw PersistenceError::queryFailed(self::summarise($sql), $error);
        }

        return $statement;
    }

    private static function parameterType(mixed $value): int
    {
        return match (true) {
            $value === null => PDO::PARAM_NULL,
            is_bool($value) => PDO::PARAM_BOOL,
            is_int($value) => PDO::PARAM_INT,
            default => PDO::PARAM_STR,
        };
    }

    /**
     * A statement short enough for a log line. SQL only — never the values.
     */
    private static function summarise(string $sql): string
    {
        $collapsed = trim((string) preg_replace('/\s+/', ' ', $sql));

        return strlen($collapsed) <= 160 ? $collapsed : substr($collapsed, 0, 157) . '...';
    }

    /**
     * @throws PersistenceError
     */
    private function begin(): void
    {
        if ($this->depth === 0) {
            $this->rollbackOnly = false;

            try {
                $this->pdo()->beginTransaction();
            } catch (PDOException $error) {
                throw PersistenceError::queryFailed('BEGIN', $error);
            }
        }

        ++$this->depth;
    }

    /**
     * @throws PersistenceError
     */
    private function finish(bool $commit): void
    {
        if ($this->depth === 0) {
            throw PersistenceError::transactionMisuse(
                'Transaction finished while none was open. This means beginTransaction() or '
                . 'commit() was called on the PDO handle directly; use Connection::transactional().',
            );
        }

        --$this->depth;

        if ($this->depth > 0) {
            // An inner block never decides. It can only take the decision away
            // from the outer one, which the rollback-only flag already did.
            return;
        }

        $pdo = $this->pdo();

        try {
            if ($commit && !$this->rollbackOnly) {
                $pdo->commit();

                return;
            }

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (PDOException $error) {
            throw PersistenceError::queryFailed($commit ? 'COMMIT' : 'ROLLBACK', $error);
        } finally {
            $this->rollbackOnly = false;
        }

        if ($commit) {
            throw PersistenceError::transactionMisuse(
                'The transaction was marked rollback-only by a nested block that failed, so the '
                . 'outermost transactional() rolled back instead of committing.',
            );
        }
    }
}
