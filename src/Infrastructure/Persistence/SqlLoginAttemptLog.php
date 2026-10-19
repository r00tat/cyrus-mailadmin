<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Mailadmin\Infrastructure\Security\LoginAttemptLog;

/**
 * `mailadmin_login_attempt`.
 *
 * Two counts and two writes, all of them index reads over
 * `(username, attempted_at)` or `(client_ip, attempted_at)`. They run on the
 * sign-in path only, which is the one path where a couple of extra queries are
 * cheaper than the alternative.
 *
 * `countForClient` is skipped by the limiter when the address is unknown, so
 * there is no query here for the empty string — but the column has a default of
 * `''` all the same, because a row without an address is still a failure worth
 * counting by name.
 */
final readonly class SqlLoginAttemptLog implements LoginAttemptLog
{
    public function __construct(private Connection $connection)
    {
    }

    public function recordFailure(string $username, string $clientIp, DateTimeImmutable $at): void
    {
        $this->connection->execute(
            'INSERT INTO `mailadmin_login_attempt` (`username`, `client_ip`, `attempted_at`) '
            . 'VALUES (:username, :ip, :at)',
            ['username' => $username, 'ip' => $clientIp, 'at' => self::stamp($at)],
        );
    }

    public function countForUsername(string $username, DateTimeImmutable $since): int
    {
        return $this->count(
            'SELECT COUNT(*) FROM `mailadmin_login_attempt` '
            . 'WHERE `username` = :key AND `attempted_at` > :since',
            $username,
            $since,
        );
    }

    public function countForClient(string $clientIp, DateTimeImmutable $since): int
    {
        return $this->count(
            'SELECT COUNT(*) FROM `mailadmin_login_attempt` '
            . 'WHERE `client_ip` = :key AND `attempted_at` > :since',
            $clientIp,
            $since,
        );
    }

    public function clearUsername(string $username): void
    {
        $this->connection->execute(
            'DELETE FROM `mailadmin_login_attempt` WHERE `username` = :username',
            ['username' => $username],
        );
    }

    public function prune(DateTimeImmutable $before): int
    {
        return $this->connection->execute(
            'DELETE FROM `mailadmin_login_attempt` WHERE `attempted_at` <= :before',
            ['before' => self::stamp($before)],
        );
    }

    private function count(string $sql, string $key, DateTimeImmutable $since): int
    {
        /** @var mixed $value */
        $value = $this->connection->selectValue($sql, ['key' => $key, 'since' => self::stamp($since)]);

        return is_numeric($value) ? (int) $value : 0;
    }

    private static function stamp(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
