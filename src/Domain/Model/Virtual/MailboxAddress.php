<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model\Virtual;

use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Domain\Model\MailboxIdentity;

/**
 * The account's own address: the row that makes `local@domain` deliver into the
 * account's mailbox.
 *
 * Shares its field pattern with {@see Alias} — both carry an address on the
 * left and a Cyrus user name on the right. What separates them is not a field
 * but a fact: this row's alias *is* the address the identity derives
 * (`MailboxIdentity::address()`). Deleting it takes the account's own address
 * away, which is why it is a type of its own and not „just another alias".
 */
final readonly class MailboxAddress implements VirtualEntry
{
    public function __construct(
        public MailboxIdentity $identity,
        public EmailAddress $address,
        private VirtualRow $row,
    ) {
    }

    public function type(): VirtualEntryType
    {
        return VirtualEntryType::MailboxAddress;
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
        return DestinationList::fromLegacyString($this->row->dest);
    }

    public function isEnabled(): bool
    {
        return $this->row->isEnabled();
    }

    /**
     * The Cyrus user name this address delivers to.
     */
    public function cyrusUsername(): string
    {
        return $this->row->username;
    }
}
