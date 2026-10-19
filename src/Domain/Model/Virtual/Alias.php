<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model\Virtual;

use Mailadmin\Domain\Model\EmailAddress;

/**
 * A further address that delivers into an existing mailbox.
 *
 * The right-hand side may name more than one target — the column is a
 * `longtext` and the legacy data uses comma separated lists.
 * `targetsSingleAccount()` says whether this is the simple case.
 */
final readonly class Alias implements VirtualEntry
{
    public function __construct(
        public EmailAddress $address,
        public DestinationList $targets,
        private VirtualRow $row,
    ) {
    }

    public function type(): VirtualEntryType
    {
        return VirtualEntryType::Alias;
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

    /**
     * The Cyrus user name in `virtual.username`.
     */
    public function cyrusUsername(): string
    {
        return $this->row->username;
    }

    public function targetsSingleAccount(): bool
    {
        return $this->targets->count() === 1
            && $this->targets->contains($this->row->username);
    }
}
