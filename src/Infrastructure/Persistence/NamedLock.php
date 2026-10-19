<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use RuntimeException;
use Throwable;

/**
 * A MariaDB `GET_LOCK` around a piece of work — the account lock of U-81 and
 * the namespace lock of U-97, held the same way so that they fail the same way.
 *
 * `GET_LOCK` works whatever the storage engine (O-11), and it is re-entrant on
 * the same connection since MariaDB 10.0.2: the namespace lock and the account
 * lock nest, and so does a namespace lock taken twice in one request. Lock
 * names are server-wide and at most 64 characters.
 *
 * A lock that is not free in time is a {@see PersistenceError}: the work did
 * not run, and to the caller that is the same as a statement that failed.
 */
final class NamedLock
{
    /** U-81. Long enough for a short database step, short enough to fail a request rather than hang it. */
    public const int TIMEOUT_SECONDS = 10;

    private function __construct()
    {
    }

    /**
     * @template T
     *
     * @param string       $name  the lock name, at most 64 characters
     * @param string       $label what the lock protects, for the error message
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws PersistenceError when the lock is not free in time
     * @throws Throwable        whatever `$work` throws
     */
    public static function hold(Connection $connection, string $name, string $label, callable $work): mixed
    {
        /** @var mixed $acquired */
        $acquired = $connection->selectValue(
            'SELECT GET_LOCK(:name, :timeout)',
            ['name' => $name, 'timeout' => self::TIMEOUT_SECONDS],
        );

        // 1 = acquired, 0 = timed out, NULL = error.
        if (!is_numeric($acquired) || (int) $acquired !== 1) {
            throw PersistenceError::queryFailed(
                $label,
                new RuntimeException(sprintf(
                    'The %s was not free within %d seconds.',
                    $label,
                    self::TIMEOUT_SECONDS,
                )),
            );
        }

        try {
            return $work();
        } finally {
            try {
                $connection->selectValue('SELECT RELEASE_LOCK(:name)', ['name' => $name]);
            } catch (Throwable) {
                // The lock goes with the connection anyway; a failed release
                // must not replace the exception the caller is handling.
            }
        }
    }
}
