<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

use DateTimeImmutable;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Page;
use Mailadmin\Infrastructure\Persistence\PersistenceError;

/**
 * Reading and closing repair-queue entries — the other half of
 * {@see \Mailadmin\Domain\Repair\RepairQueue}, which only ever appends.
 *
 * Two ports rather than one because their callers differ in what they may do. An
 * operation that failed half way records and must not fail doing so; the
 * maintenance use cases list, retry and resolve, and a database error there is
 * an ordinary error. One implementation serves both
 * ({@see \Mailadmin\Infrastructure\Persistence\RepairQueueRepository}).
 */
interface RepairLedger
{
    /**
     * @return Page<RepairEntry>
     *
     * @throws PersistenceError
     */
    public function list(ListCriteria $criteria): Page;

    /**
     * Reads the current row every time; the answer changes after a retry or a
     * resolution in the same request.
     *
     * @phpstan-impure
     *
     * @throws PersistenceError
     */
    public function find(int $id): ?RepairEntry;

    /**
     * Runs `$work` while no other retry or dismissal of entry `$id` can run.
     *
     * A retry reads the entry, acts on Cyrus and then closes the entry; a
     * dismissal reads and closes it. Without the lock both can read `open`,
     * and a retry would delete a mailbox an administrator has just decided to
     * keep. Whoever holds the lock re-reads the entry inside `$work`.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws PersistenceError when the lock is not free in time
     */
    public function withEntryLock(int $id, callable $work): mixed;

    /**
     * One more failed retry: count it and keep the code. Changes nothing on
     * an entry that is no longer open.
     *
     * @throws PersistenceError
     */
    public function recordAttempt(int $id, string $errorCode, DateTimeImmutable $at): void;

    /**
     * Close an open entry. Returns `false` when it was no longer open — somebody
     * else resolved it in the meantime — and changes nothing then.
     *
     * @throws PersistenceError
     */
    public function resolve(
        int $id,
        RepairResolution $resolution,
        string $resolvedBy,
        string $note,
        DateTimeImmutable $at,
    ): bool;
}
