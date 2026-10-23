<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Support;

use Mailadmin\Application\Address\AddressStore;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\Virtual\VirtualRow;

/**
 * An address store that writes every insert and removal into a
 * {@see CallTrace} before passing it on — the `virtual` half of
 * {@see TracingMailingListRepository}.
 */
final readonly class TracingAddressStore implements AddressStore
{
    public function __construct(private AddressStore $inner, private CallTrace $trace)
    {
    }

    public function rowsOfDomain(DomainName $domain): array
    {
        return $this->inner->rowsOfDomain($domain);
    }

    public function rowsWithAlias(string $alias): array
    {
        return $this->inner->rowsWithAlias($alias);
    }

    public function forwardRowsOf(string $storedUsername): array
    {
        return $this->inner->forwardRowsOf($storedUsername);
    }

    public function accountDomains(array $usernames): array
    {
        return $this->inner->accountDomains($usernames);
    }

    public function addressTaken(string $address): bool
    {
        return $this->inner->addressTaken($address);
    }

    public function isAccountName(string $name): bool
    {
        return $this->inner->isAccountName($name);
    }

    public function insert(VirtualRow $row): void
    {
        $this->trace->add('virtual.insert ' . $row->alias);
        $this->inner->insert($row);
    }

    public function remove(VirtualRow $row): bool
    {
        $this->trace->add('virtual.remove ' . $row->alias);

        return $this->inner->remove($row);
    }

    public function rewrite(VirtualRow $row, string $dest, string $username): bool
    {
        $this->trace->add('virtual.rewrite ' . $row->alias);

        return $this->inner->rewrite($row, $dest, $username);
    }

    public function count(VirtualRow $row): int
    {
        return $this->inner->count($row);
    }

    public function atomically(callable $work): mixed
    {
        return $this->inner->atomically($work);
    }
}
