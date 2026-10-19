<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Repair;

use Mailadmin\Application\Maintenance\InventoryAccount;
use Mailadmin\Application\Maintenance\InventoryDomain;
use Mailadmin\Application\Maintenance\MaintenanceInventory;
use Mailadmin\Domain\Model\Virtual\VirtualRow;

/**
 * The reconciliation's view of the database, given row by row in a test.
 * Never wired into the container.
 */
final class InMemoryMaintenanceInventory implements MaintenanceInventory
{
    /** @var list<InventoryAccount> */
    private array $accounts = [];

    /** @var list<InventoryDomain> */
    private array $domains = [];

    /** @var list<VirtualRow> */
    private array $rows = [];

    /** @var list<string> */
    private array $lists = [];

    public function givenAccount(string $username, string $domain): self
    {
        $this->accounts[] = new InventoryAccount($username, $domain);

        return $this;
    }

    public function givenDomain(string $name, string $transport = InventoryDomain::LOCAL_TRANSPORT): self
    {
        $this->domains[] = new InventoryDomain($name, $transport);

        return $this;
    }

    public function givenRow(VirtualRow $row): self
    {
        $this->rows[] = $row;

        return $this;
    }

    public function givenList(string $address): self
    {
        $this->lists[] = $address;

        return $this;
    }

    public function accounts(): array
    {
        return $this->accounts;
    }

    public function domains(): array
    {
        return $this->domains;
    }

    public function virtualRows(): array
    {
        return $this->rows;
    }

    public function listAddresses(): array
    {
        return $this->lists;
    }
}
