<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

use Mailadmin\Domain\Access\ActorContext;

/**
 * `GET /maintenance/repairs/{repair}`.
 */
final readonly class GetRepair
{
    public function __construct(private RepairLedger $ledger)
    {
    }

    /**
     * @throws RepairOperationFailed
     */
    public function handle(ActorContext $actor, int $id): RepairEntry
    {
        return $this->ledger->find($id) ?? throw RepairOperationFailed::notFound($id);
    }
}
