<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model\Virtual;

/**
 * A forward to the outside: `username` is empty, `alias` is the Cyrus user name
 * whose mail is sent on, `dest` names where to.
 *
 * The empty `username` is what makes the row a forward — with a user name
 * Postfix would deliver locally as well. When `dest` also contains the local
 * account, the user keeps a copy; that is the legacy way of expressing „forward
 * and keep" and {@see keepsLocalCopy()} is what asks it (A3, open point 2).
 */
final readonly class Forward implements VirtualEntry
{
    public function __construct(
        public string $source,
        public DestinationList $targets,
        private VirtualRow $row,
    ) {
    }

    public function type(): VirtualEntryType
    {
        return VirtualEntryType::Forward;
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
     * Does the forward also deliver into the mailbox it comes from?
     */
    public function keepsLocalCopy(): bool
    {
        return $this->targets->contains($this->source);
    }
}
