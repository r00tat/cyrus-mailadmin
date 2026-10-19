<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use DateTimeImmutable;
use Mailadmin\Infrastructure\Security\RefreshTokenRecord;
use Mailadmin\Infrastructure\Security\RefreshTokenStore;

/**
 * Refresh tokens in an array.
 *
 * {@see consume()} reproduces the conditional `UPDATE` of
 * {@see SqlRefreshTokenStore}: it succeeds exactly once for a usable token and
 * is false thereafter. Single-threaded, so it cannot demonstrate the atomicity
 * the SQL version gets from a row lock — but it does enforce the *rule* the
 * atomicity exists to guarantee, which is what the rotation tests are about.
 */
final class InMemoryRefreshTokenStore implements RefreshTokenStore
{
    /** @var array<string, RefreshTokenRecord> */
    private array $records = [];

    public function store(RefreshTokenRecord $record): void
    {
        $this->records[$record->tokenHash] = $record;
    }

    public function find(string $tokenHash): ?RefreshTokenRecord
    {
        return $this->records[$tokenHash] ?? null;
    }

    public function consume(string $tokenHash, DateTimeImmutable $at): bool
    {
        $record = $this->records[$tokenHash] ?? null;

        if ($record === null || !$record->isUsable($at)) {
            return false;
        }

        $this->records[$tokenHash] = $record->consumedAt($at);

        return true;
    }

    public function revokeFamily(string $family, DateTimeImmutable $at): int
    {
        return $this->revokeWhere(
            static fn (RefreshTokenRecord $record): bool => $record->family === $family,
            $at,
        );
    }

    public function revokeSubject(string $subject, DateTimeImmutable $at): int
    {
        return $this->revokeWhere(
            static fn (RefreshTokenRecord $record): bool => $record->subject === $subject,
            $at,
        );
    }

    public function prune(DateTimeImmutable $before): int
    {
        $removed = 0;

        foreach ($this->records as $hash => $record) {
            if ($record->expiresAt <= $before) {
                unset($this->records[$hash]);
                ++$removed;
            }
        }

        return $removed;
    }

    /**
     * Everything stored, for a test that wants to assert on the family rather
     * than on one token.
     *
     * @return list<RefreshTokenRecord>
     */
    public function all(): array
    {
        return array_values($this->records);
    }

    /**
     * @param callable(RefreshTokenRecord): bool $matches
     */
    private function revokeWhere(callable $matches, DateTimeImmutable $at): int
    {
        $changed = 0;

        foreach ($this->records as $hash => $record) {
            if ($record->isRevoked() || !$matches($record)) {
                continue;
            }

            $this->records[$hash] = $record->revokedAt($at);
            ++$changed;
        }

        return $changed;
    }
}
