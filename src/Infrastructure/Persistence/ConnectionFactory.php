<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use PDO;
use PDOException;

/**
 * Opens the one connection the application uses (architecture 10.1).
 *
 * The four settings below are not preferences:
 *
 *  - **`ATTR_ERRMODE = EXCEPTION`** — the alternative is a `false` return value
 *    that a caller forgets to check, which is how the old system lost errors.
 *  - **`ATTR_EMULATE_PREPARES = false`** — with emulation on, PDO interpolates
 *    the values itself and "prepared statement" becomes a statement about style
 *    rather than about safety. Off, the values travel out of band and the server
 *    never parses them as SQL (`CLAUDE.md`, rule 2).
 *  - **`SET time_zone = '+00:00'`** — U-14. Every `DATETIME` this application
 *    writes is UTC; without this, `NOW()` and `CURRENT_TIMESTAMP` would follow
 *    the server's zone and the audit trail would jump twice a year.
 *  - **`charset=utf8mb4`** in the DSN — enforced by
 *    {@see DatabaseConfiguration}.
 *
 * `ATTR_STRINGIFY_FETCHES` is left off so that an `INT` column arrives as an
 * `int`; the mapping code in the A packages is analysed at PHPStan level 8 and
 * would otherwise spend its time on `(int)` casts.
 *
 * The connection is **not** opened here — {@see Connection} does that on first
 * use. A request that never touches the database must not pay for a TCP
 * handshake, and the startup path has to work while the database is down long
 * enough to say so.
 */
final readonly class ConnectionFactory implements ConnectionSource
{
    /**
     * Session settings applied to every fresh handle, in order.
     *
     * A constant rather than inline strings so that a test can assert the list
     * and a reader can see the whole session contract in one place.
     *
     * @var list<string>
     */
    public const array SESSION_STATEMENTS = [
        // U-14. Written as an offset, not as a zone name: `SET time_zone = 'UTC'`
        // needs the time zone tables to be loaded, which they frequently are not.
        "SET time_zone = '+00:00'",
    ];

    public function __construct(private DatabaseConfiguration $configuration)
    {
    }

    public function connect(): PDO
    {
        try {
            $pdo = new PDO(
                $this->configuration->dsn,
                $this->configuration->user,
                $this->configuration->password(),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                    PDO::ATTR_TIMEOUT => $this->configuration->timeoutSeconds,
                ],
            );
        } catch (PDOException $error) {
            throw PersistenceError::unavailable($this->configuration->redactedDsn(), $error);
        }

        foreach (self::SESSION_STATEMENTS as $statement) {
            try {
                $pdo->exec($statement);
            } catch (PDOException $error) {
                throw PersistenceError::unavailable($this->configuration->redactedDsn(), $error);
            }
        }

        return $pdo;
    }

    public function describe(): string
    {
        return $this->configuration->redactedDsn();
    }

    public function configuration(): DatabaseConfiguration
    {
        return $this->configuration;
    }
}
