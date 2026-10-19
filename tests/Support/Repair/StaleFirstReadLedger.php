<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Support\Repair;

use DateTimeImmutable;
use Mailadmin\Application\Maintenance\RepairEntry;
use Mailadmin\Application\Maintenance\RepairLedger;
use Mailadmin\Application\Maintenance\RepairResolution;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Page;
use Mailadmin\Infrastructure\Repair\InMemoryRepairLedger;

/**
 * The race of two administrators, in one thread: the first read of each entry
 * answers what the entry looked like when this double was made, every later
 * read answers what it is now.
 *
 * That is what a retry sees when a dismissal lands between its first read and
 * its act. With `refuseResolve`, closing reports "no longer open" — the answer
 * a conditional `UPDATE` gives when the row was closed by hand meanwhile.
 */
final class StaleFirstReadLedger implements RepairLedger
{
    /** @var array<int, RepairEntry> */
    private array $snapshot = [];

    public function __construct(
        private readonly InMemoryRepairLedger $inner,
        private readonly bool $refuseResolve = false,
    ) {
        foreach ($inner->entries() as $entry) {
            $this->snapshot[$entry->id] = $entry;
        }
    }

    public function list(ListCriteria $criteria): Page
    {
        return $this->inner->list($criteria);
    }

    public function find(int $id): ?RepairEntry
    {
        if (isset($this->snapshot[$id])) {
            $stale = $this->snapshot[$id];
            unset($this->snapshot[$id]);

            return $stale;
        }

        return $this->inner->find($id);
    }

    public function withEntryLock(int $id, callable $work): mixed
    {
        return $this->inner->withEntryLock($id, $work);
    }

    public function recordAttempt(int $id, string $errorCode, DateTimeImmutable $at): void
    {
        $this->inner->recordAttempt($id, $errorCode, $at);
    }

    public function resolve(
        int $id,
        RepairResolution $resolution,
        string $resolvedBy,
        string $note,
        DateTimeImmutable $at,
    ): bool {
        if ($this->refuseResolve) {
            return false;
        }

        return $this->inner->resolve($id, $resolution, $resolvedBy, $note, $at);
    }
}
