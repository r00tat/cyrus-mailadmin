<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model\Virtual;

use Mailadmin\Domain\Model\EmailAddress;

/**
 * The `virtual` row that routes a mailing list's address (A5).
 *
 * It is owned by the list, not by the address management: it is changed and
 * removed only through `/lists`, together with the `lists` row. The address
 * management shows it, read-only, with {@see $address} as the list to link to.
 *
 * Two forms exist — the transport form (`dest` on the pseudo-domain, empty
 * `username`) and the legacy form (`dest = username = <list key>`); see
 * {@see VirtualEntryType}.
 */
final readonly class MailingListEntry implements VirtualEntry
{
    public function __construct(
        public EmailAddress $address,
        public DestinationList $targets,
        private VirtualRow $row,
    ) {
    }

    public function type(): VirtualEntryType
    {
        return VirtualEntryType::MailingList;
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
