<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\Virtual\CatchAll;
use Mailadmin\Domain\Model\Virtual\DestinationList;
use Mailadmin\Domain\Model\Virtual\VirtualEntry;
use Mailadmin\Domain\Model\Virtual\VirtualEntryType;
use Mailadmin\Domain\Model\Virtual\VirtualRow;

/**
 * The catchall of a domain: every row with `alias = '@<domain>'`.
 *
 * Postfix prefers a concrete address — an alias or an account address — and
 * only falls back to this row for an address that has none. A *disabled*
 * concrete alias has no row Postfix reads, so its mail goes to the catchall
 * (A3 open point 4, U-108).
 */
final readonly class CatchallState
{
    public function __construct(
        public DomainName $domain,
        public ClassifiedRows $rows,
    ) {
    }

    public static function alias(DomainName $domain): string
    {
        return '@' . $domain->toString();
    }

    /**
     * @return list<CatchAll>
     */
    public function catchalls(): array
    {
        return array_values(array_filter(
            $this->rows->entries,
            static fn (VirtualEntry $entry): bool => $entry instanceof CatchAll,
        ));
    }

    public function isConfigured(): bool
    {
        return $this->catchalls() !== [];
    }

    public function destinations(): DestinationList
    {
        return AliasState::union($this->catchalls());
    }

    public function isEnabled(): bool
    {
        $catchalls = $this->catchalls();

        foreach ($catchalls as $catchall) {
            if (!$catchall->isEnabled()) {
                return false;
            }
        }

        return $catchalls !== [];
    }

    public function senderAccount(): ?string
    {
        $sender = null;

        foreach ($this->catchalls() as $index => $catchall) {
            $current = $this->rows->senderOf($catchall);

            if ($index > 0 && $current !== $sender) {
                return null;
            }

            $sender = $current;
        }

        return $sender;
    }

    /**
     * No rows, or only catchall rows that agree about `username` (U-105).
     */
    public function isEditable(): bool
    {
        return $this->rows->allOfType(VirtualEntryType::CatchAll)
            && AliasState::sameUsername($this->catchalls());
    }

    /**
     * @return list<VirtualRow>
     */
    public function catchallRows(): array
    {
        return array_map(static fn (CatchAll $catchall): VirtualRow => $catchall->row(), $this->catchalls());
    }
}
