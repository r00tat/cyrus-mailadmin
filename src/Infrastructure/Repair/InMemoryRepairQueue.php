<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Repair;

use Mailadmin\Domain\Repair\RepairQueue;
use Mailadmin\Domain\Repair\RepairRequest;

/**
 * The repair queue in an array — how a use-case test asserts that a
 * half-finished operation was recorded rather than swallowed.
 *
 * It sits beside {@see LoggingRepairQueue} in `src/` rather than in `tests/` for
 * the same reason the other doubles do (S2, M2): it is the reference for what
 * the port promises, and a test in another package should be able to reach for
 * it without depending on A1's test tree.
 *
 * **It is never wired into the container.** A misconfigured installation that
 * recorded its inconsistencies into a PHP array and then exited would be worse
 * than one that recorded none, because it would look like it was working.
 */
final class InMemoryRepairQueue implements RepairQueue
{
    /** @var list<RepairRequest> */
    private array $entries = [];

    public function record(RepairRequest $request): void
    {
        $this->entries[] = $request;
    }

    /**
     * @return list<RepairRequest>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * The `<operation>/<step>` pairs recorded, in order — usually the whole
     * assertion a test wants to make.
     *
     * @return list<string>
     */
    public function steps(): array
    {
        return array_map(
            static fn (RepairRequest $entry): string => $entry->operation . '/' . $entry->step,
            $this->entries,
        );
    }

    public function clear(): void
    {
        $this->entries = [];
    }
}
