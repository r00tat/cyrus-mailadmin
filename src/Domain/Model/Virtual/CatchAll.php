<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model\Virtual;

use Mailadmin\Domain\Model\DomainName;

/**
 * The catchall of a domain: `alias = '@domain'`.
 *
 * Everything addressed to the domain that no other row matches ends up here.
 * Postfix prefers a concrete address over the catchall — worth saying in the
 * interface (A3, open point 4), and the reason the type is explicit rather than
 * „an alias whose local part happens to be empty".
 */
final readonly class CatchAll implements VirtualEntry
{
    public function __construct(
        public DomainName $domain,
        public DestinationList $targets,
        private VirtualRow $row,
    ) {
    }

    public function type(): VirtualEntryType
    {
        return VirtualEntryType::CatchAll;
    }

    public function row(): VirtualRow
    {
        return $this->row;
    }

    public function alias(): string
    {
        return $this->row->alias;
    }

    public function destinations(): DestinationList
    {
        return $this->targets;
    }

    public function isEnabled(): bool
    {
        return $this->row->isEnabled();
    }
}
