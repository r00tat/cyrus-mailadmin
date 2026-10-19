<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use PDO;

/**
 * A {@see ConnectionSource} over a handle that is already open.
 *
 * Exists so that the repository base can be exercised without a MySQL server —
 * the paging, sorting and transaction code is plain SQL and runs against
 * SQLite — and so that a diagnostic command can pass in the handle it opened.
 * Nothing in the application wires this; it is a seam, not a strategy.
 */
final readonly class OpenConnection implements ConnectionSource
{
    public function __construct(
        private PDO $pdo,
        private string $description = 'an already open connection',
    ) {
    }

    public function connect(): PDO
    {
        return $this->pdo;
    }

    public function describe(): string
    {
        return $this->description;
    }
}
