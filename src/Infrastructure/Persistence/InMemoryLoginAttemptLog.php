<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use DateTimeImmutable;
use Mailadmin\Infrastructure\Security\LoginAttemptLog;

/**
 * Failed sign-ins in an array.
 *
 * The window comparison is `>` on both sides, matching the SQL. An off-by-one
 * between double and table would show up as a rate-limiter test that passes and
 * a limiter that locks one attempt too early in production.
 */
final class InMemoryLoginAttemptLog implements LoginAttemptLog
{
    /** @var list<array{username: string, ip: string, at: DateTimeImmutable}> */
    private array $attempts = [];

    public function recordFailure(string $username, string $clientIp, DateTimeImmutable $at): void
    {
        $this->attempts[] = ['username' => $username, 'ip' => $clientIp, 'at' => $at];
    }

    public function countForUsername(string $username, DateTimeImmutable $since): int
    {
        return $this->countWhere(
            static fn (array $attempt): bool => $attempt['username'] === $username,
            $since,
        );
    }

    public function countForClient(string $clientIp, DateTimeImmutable $since): int
    {
        return $this->countWhere(
            static fn (array $attempt): bool => $attempt['ip'] === $clientIp,
            $since,
        );
    }

    public function clearUsername(string $username): void
    {
        $this->attempts = array_values(array_filter(
            $this->attempts,
            static fn (array $attempt): bool => $attempt['username'] !== $username,
        ));
    }

    public function prune(DateTimeImmutable $before): int
    {
        $kept = array_values(array_filter(
            $this->attempts,
            static fn (array $attempt): bool => $attempt['at'] > $before,
        ));

        $removed = count($this->attempts) - count($kept);
        $this->attempts = $kept;

        return $removed;
    }

    public function total(): int
    {
        return count($this->attempts);
    }

    /**
     * @param callable(array{username: string, ip: string, at: DateTimeImmutable}): bool $matches
     */
    private function countWhere(callable $matches, DateTimeImmutable $since): int
    {
        $count = 0;

        foreach ($this->attempts as $attempt) {
            if ($attempt['at'] > $since && $matches($attempt)) {
                ++$count;
            }
        }

        return $count;
    }
}
