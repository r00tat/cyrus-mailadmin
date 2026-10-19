<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Domain\Model\Virtual\Alias;
use Mailadmin\Domain\Model\Virtual\DestinationList;
use Mailadmin\Domain\Model\Virtual\VirtualEntry;
use Mailadmin\Domain\Model\Virtual\VirtualEntryType;
use Mailadmin\Domain\Model\Virtual\VirtualRow;

/**
 * An alias as A3 shows and edits it: every row under one address.
 *
 * Legacy data spreads one alias over several rows — webcyradm's alias editor
 * wrote one row per destination — and Postfix answers with all of them. So the
 * resource is the address, and its destinations are the union of its rows
 * (U-105). The rows themselves stay visible in `entries`.
 */
final readonly class AliasState
{
    public function __construct(
        public EmailAddress $address,
        public ClassifiedRows $rows,
    ) {
    }

    /**
     * @return list<Alias>
     */
    public function aliases(): array
    {
        return array_values(array_filter(
            $this->rows->entries,
            static fn (VirtualEntry $entry): bool => $entry instanceof Alias,
        ));
    }

    public function exists(): bool
    {
        return $this->aliases() !== [];
    }

    public function destinations(): DestinationList
    {
        return self::union($this->aliases());
    }

    public function isEnabled(): bool
    {
        $aliases = $this->aliases();

        foreach ($aliases as $alias) {
            if (!$alias->isEnabled()) {
                return false;
            }
        }

        return $aliases !== [];
    }

    /**
     * The account `validsenders` lets send as this address, when every row
     * names the same one.
     */
    public function senderAccount(): ?string
    {
        $sender = null;

        foreach ($this->aliases() as $index => $alias) {
            $current = $this->rows->senderOf($alias);

            if ($index > 0 && $current !== $sender) {
                return null;
            }

            $sender = $current;
        }

        return $sender;
    }

    /**
     * The rows can be rewritten as one: all of them aliases, all of them
     * naming the same `username` and all of them with the same `status`
     * (U-105, U-111). Anything else would mean dropping a row we do not
     * understand, deciding between two sending rights, or — for a `PATCH`
     * that leaves `enabled` out — switching an active destination off or a
     * disabled one on that nobody asked about.
     */
    public function isEditable(): bool
    {
        return $this->exists()
            && $this->rows->allOfType(VirtualEntryType::Alias)
            && self::sameUsername($this->aliases())
            && self::sameStatus($this->aliases());
    }

    /**
     * @return list<VirtualRow>
     */
    public function aliasRows(): array
    {
        return array_map(static fn (Alias $alias): VirtualRow => $alias->row(), $this->aliases());
    }

    public function storedUsername(): ?string
    {
        $aliases = $this->aliases();

        return $aliases === [] ? null : $aliases[0]->row()->username;
    }

    /**
     * @param list<VirtualEntry> $entries
     */
    public static function union(array $entries): DestinationList
    {
        $all = [];

        foreach ($entries as $entry) {
            foreach ($entry->destinations()->all() as $destination) {
                $all[] = $destination;
            }
        }

        return DestinationList::fromLegacyString(implode(DestinationList::SEPARATOR, $all));
    }

    /**
     * @param list<VirtualEntry> $entries
     */
    public static function sameStatus(array $entries): bool
    {
        $first = null;

        foreach ($entries as $entry) {
            $enabled = $entry->isEnabled();
            $first ??= $enabled;

            if ($enabled !== $first) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<VirtualEntry> $entries
     */
    public static function sameUsername(array $entries): bool
    {
        $first = null;

        foreach ($entries as $entry) {
            $username = strtolower(trim($entry->row()->username));
            $first ??= $username;

            if ($username !== $first) {
                return false;
            }
        }

        return true;
    }
}
