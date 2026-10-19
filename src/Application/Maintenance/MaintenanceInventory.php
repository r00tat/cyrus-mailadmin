<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

use Mailadmin\Domain\Model\Virtual\VirtualRow;
use Mailadmin\Infrastructure\Persistence\PersistenceError;

/**
 * Everything the database says should exist — read whole, once, for the
 * reconciliation (A6).
 *
 * Whole tables rather than pages: a reconciliation compares two complete sets,
 * and an installation of this size has a few thousand rows (U-115). Read
 * verbatim and without interpretation — what is wrong with a row is the
 * reconciliation's to say, and it can only say it if the row reaches it.
 */
interface MaintenanceInventory
{
    /**
     * @return list<InventoryAccount>
     *
     * @throws PersistenceError
     */
    public function accounts(): array;

    /**
     * @return list<InventoryDomain>
     *
     * @throws PersistenceError
     */
    public function domains(): array;

    /**
     * @return list<VirtualRow>
     *
     * @throws PersistenceError
     */
    public function virtualRows(): array;

    /**
     * The addresses of the mailing lists in `lists`, which Postfix delivers
     * through the Salt-generated configuration rather than `virtual` (E-9).
     *
     * @return list<string>
     *
     * @throws PersistenceError
     */
    public function listAddresses(): array;
}
