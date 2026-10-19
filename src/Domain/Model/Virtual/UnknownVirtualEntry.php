<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model\Virtual;

/**
 * A `virtual` row that fits none of the four patterns.
 *
 * It is **shown, not skipped** — `CLAUDE.md` and U-22 both say so, for the same
 * reason: the row is active in Postfix. An entry that is quietly passed over is
 * worse than one that stands out as „unknown", because the first looks like it
 * does not exist while it keeps delivering mail.
 *
 * The row is carried unchanged so it can be written back untouched. Nothing in
 * the application may repair such an entry on its own (E-18).
 */
final readonly class UnknownVirtualEntry implements VirtualEntry
{
    public function __construct(
        public UnknownReason $reason,
        private VirtualRow $row,
    ) {
    }

    public function type(): VirtualEntryType
    {
        return VirtualEntryType::Unknown;
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
}
