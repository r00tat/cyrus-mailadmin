<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Repair;

use DateTimeImmutable;
use Mailadmin\Application\Maintenance\RepairEntry;
use Mailadmin\Application\Maintenance\RepairLedger;
use Mailadmin\Application\Maintenance\RepairResolution;
use Mailadmin\Application\Maintenance\RepairStatus;
use Mailadmin\Domain\Repair\RepairQueue;
use Mailadmin\Domain\Repair\RepairRequest;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortDirection;
use Mailadmin\Infrastructure\Persistence\Page;

/**
 * The repair queue with both of its faces in an array — a use case that fails
 * half way records here, and the maintenance use cases read and close what it
 * recorded. Mirrors {@see \Mailadmin\Infrastructure\Persistence\RepairQueueRepository}.
 *
 * **Never wired into the container**, for the reason {@see InMemoryRepairQueue}
 * gives.
 */
final class InMemoryRepairLedger implements RepairQueue, RepairLedger
{
    /** @var array<int, RepairEntry> */
    private array $entries = [];

    private int $nextId = 1;

    private int $locksTaken = 0;

    public function __construct(private readonly DateTimeImmutable $now = new DateTimeImmutable('2026-08-15 09:00:00 UTC'))
    {
    }

    public function record(RepairRequest $request): void
    {
        $id = $this->nextId++;

        $this->entries[$id] = new RepairEntry(
            id: $id,
            recordedAt: $this->now,
            operation: $request->operation,
            step: $request->step,
            subject: $request->subject,
            intended: $request->intended,
            reached: $request->reached,
            reason: $request->reason,
            parameters: $request->parameters,
            requestId: '',
            status: RepairStatus::Open,
        );
    }

    public function list(ListCriteria $criteria): Page
    {
        $items = array_values(array_filter(
            $this->entries,
            static function (RepairEntry $entry) use ($criteria): bool {
                $status = $criteria->filters['status'] ?? null;
                $operation = $criteria->filters['operation'] ?? null;
                $step = $criteria->filters['step'] ?? null;

                return (!is_string($status) || $entry->status->value === $status)
                    && (!is_string($operation) || str_starts_with($entry->operation, $operation))
                    && (!is_string($step) || $entry->step === $step);
            },
        ));

        // The ordering the repository applies: the chosen field in the chosen
        // direction, then `id` ascending as the tiebreaker.
        $sign = $criteria->order === SortDirection::Descending ? -1 : 1;
        usort($items, static function (RepairEntry $left, RepairEntry $right) use ($criteria, $sign): int {
            $comparison = match ($criteria->sort) {
                'recorded_at' => $left->recordedAt <=> $right->recordedAt,
                'operation' => strcmp($left->operation, $right->operation),
                default => $left->id <=> $right->id,
            };

            return $comparison !== 0 ? $sign * $comparison : $left->id <=> $right->id;
        });

        return Page::of(
            array_slice($items, $criteria->offset(), $criteria->perPage),
            $criteria,
            count($items),
        );
    }

    public function find(int $id): ?RepairEntry
    {
        return $this->entries[$id] ?? null;
    }

    public function withEntryLock(int $id, callable $work): mixed
    {
        ++$this->locksTaken;

        return $work();
    }

    public function locksTaken(): int
    {
        return $this->locksTaken;
    }

    public function recordAttempt(int $id, string $errorCode, DateTimeImmutable $at): void
    {
        $entry = $this->entries[$id] ?? null;

        if ($entry === null || !$entry->isOpen()) {
            return;
        }

        $this->entries[$id] = self::with($entry, attempts: $entry->attempts + 1, lastAttemptAt: $at, lastError: $errorCode);
    }

    public function resolve(
        int $id,
        RepairResolution $resolution,
        string $resolvedBy,
        string $note,
        DateTimeImmutable $at,
    ): bool {
        $entry = $this->entries[$id] ?? null;

        if ($entry === null || !$entry->isOpen()) {
            return false;
        }

        $this->entries[$id] = self::with(
            $entry,
            status: RepairStatus::Resolved,
            resolvedAt: $at,
            resolvedBy: $resolvedBy,
            resolution: $resolution,
            note: $note,
        );

        return true;
    }

    /**
     * @return list<RepairEntry>
     */
    public function entries(): array
    {
        return array_values($this->entries);
    }

    private static function with(
        RepairEntry $entry,
        ?RepairStatus $status = null,
        ?int $attempts = null,
        ?DateTimeImmutable $lastAttemptAt = null,
        ?string $lastError = null,
        ?DateTimeImmutable $resolvedAt = null,
        ?string $resolvedBy = null,
        ?RepairResolution $resolution = null,
        ?string $note = null,
    ): RepairEntry {
        return new RepairEntry(
            id: $entry->id,
            recordedAt: $entry->recordedAt,
            operation: $entry->operation,
            step: $entry->step,
            subject: $entry->subject,
            intended: $entry->intended,
            reached: $entry->reached,
            reason: $entry->reason,
            parameters: $entry->parameters,
            requestId: $entry->requestId,
            status: $status ?? $entry->status,
            attempts: $attempts ?? $entry->attempts,
            lastAttemptAt: $lastAttemptAt ?? $entry->lastAttemptAt,
            lastError: $lastError ?? $entry->lastError,
            resolvedAt: $resolvedAt ?? $entry->resolvedAt,
            resolvedBy: $resolvedBy ?? $entry->resolvedBy,
            resolution: $resolution ?? $entry->resolution,
            note: $note ?? $entry->note,
        );
    }
}
