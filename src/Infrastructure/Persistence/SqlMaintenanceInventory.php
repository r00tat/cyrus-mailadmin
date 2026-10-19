<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use Mailadmin\Application\Maintenance\InventoryAccount;
use Mailadmin\Application\Maintenance\InventoryDomain;
use Mailadmin\Application\Maintenance\MaintenanceInventory;
use Mailadmin\Domain\Model\Virtual\VirtualRow;

/**
 * The four legacy tables the reconciliation compares, read whole and verbatim
 * (A6). Read-only: nothing in this class writes.
 *
 * Fixed statements with no parameters at all, so there is nothing to bind and
 * nothing to interpolate (CLAUDE.md, rule 2).
 */
final readonly class SqlMaintenanceInventory implements MaintenanceInventory
{
    public function __construct(private Connection $connection)
    {
    }

    public function accounts(): array
    {
        $accounts = [];

        foreach ($this->connection->select(
            'SELECT `username`, `domain_name` FROM `accountuser` ORDER BY `username`',
        ) as $row) {
            $accounts[] = new InventoryAccount(self::text($row['username'] ?? null), self::text($row['domain_name'] ?? null));
        }

        return $accounts;
    }

    public function domains(): array
    {
        $domains = [];

        foreach ($this->connection->select(
            'SELECT `domain_name`, `transport` FROM `domain` ORDER BY `domain_name`',
        ) as $row) {
            $domains[] = new InventoryDomain(self::text($row['domain_name'] ?? null), self::text($row['transport'] ?? null));
        }

        return $domains;
    }

    public function virtualRows(): array
    {
        $rows = [];

        foreach ($this->connection->select(
            'SELECT `alias`, `dest`, `username`, `status` FROM `virtual` ORDER BY `alias`, `username`',
        ) as $row) {
            $rows[] = new VirtualRow(
                self::text($row['alias'] ?? null),
                self::text($row['dest'] ?? null),
                self::text($row['username'] ?? null),
                (int) self::text($row['status'] ?? null),
            );
        }

        return $rows;
    }

    public function listAddresses(): array
    {
        $addresses = [];

        foreach ($this->connection->select('SELECT `address` FROM `lists` ORDER BY `address`') as $row) {
            $address = self::text($row['address'] ?? null);

            if ($address !== '') {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
