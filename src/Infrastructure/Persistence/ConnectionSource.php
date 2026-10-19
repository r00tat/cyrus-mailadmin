<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use PDO;

/**
 * Where a {@see Connection} gets its handle from.
 *
 * Two implementations: {@see ConnectionFactory} opens one from the
 * configuration, {@see OpenConnection} hands over a handle that already exists.
 * The second one is what lets a test drive the repository base against an
 * in-memory database, and what lets a command reuse a handle it opened itself
 * to print a diagnosis.
 */
interface ConnectionSource
{
    /**
     * @throws PersistenceError when no connection can be established
     */
    public function connect(): PDO;

    /**
     * How the connection may be named in a log line — never with a password.
     */
    public function describe(): string;
}
