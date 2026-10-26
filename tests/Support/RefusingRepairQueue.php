<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Support;

use Mailadmin\Domain\Repair\RepairQueue;
use Mailadmin\Domain\Repair\RepairRequest;
use Mailadmin\Infrastructure\Persistence\PersistenceError;

/**
 * A repair queue that refuses every entry — for the case where the database
 * that failed a step is also the one that holds the queue.
 */
final class RefusingRepairQueue implements RepairQueue
{
    private int $attempts = 0;

    public function record(RepairRequest $request): void
    {
        ++$this->attempts;

        throw PersistenceError::transactionMisuse('repair queue refused');
    }

    public function attempts(): int
    {
        return $this->attempts;
    }
}
