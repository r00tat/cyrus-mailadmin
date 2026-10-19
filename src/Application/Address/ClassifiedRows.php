<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\Virtual\VirtualEntry;
use Mailadmin\Domain\Model\Virtual\VirtualEntryType;

/**
 * Rows of `virtual`, classified once (architecture 3.4), together with what the
 * classification needed to know: which user names are accounts.
 *
 * The second half is what answers "who may send as this address": `validsenders`
 * joins `virtual.username` against `accountuser`, so a row grants sending rights
 * exactly when its `username` is an account (U-106).
 */
final readonly class ClassifiedRows
{
    /**
     * @param list<VirtualEntry>        $entries
     * @param array<string, DomainName> $accounts lower-cased user name => domain
     * @param array<string, string>     $stored   lower-cased user name => stored spelling
     */
    public function __construct(
        public array $entries,
        private array $accounts,
        private array $stored,
    ) {
    }

    /**
     * The stored user name of the account the row grants sending rights to,
     * or `null` when its `username` is no account.
     */
    public function senderOf(VirtualEntry $entry): ?string
    {
        $username = trim($entry->row()->username);

        if ($username === '') {
            return null;
        }

        return $this->stored[strtolower($username)] ?? null;
    }

    public function isAccount(string $username): bool
    {
        return isset($this->accounts[strtolower(trim($username))]);
    }

    /**
     * @return list<VirtualEntry>
     */
    public function ofType(VirtualEntryType $type): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (VirtualEntry $entry): bool => $entry->type() === $type,
        ));
    }

    /**
     * Every entry is of `$type` — the condition for editing a set of rows as
     * one thing (U-105).
     */
    public function allOfType(VirtualEntryType $type): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->type() !== $type) {
                return false;
            }
        }

        return true;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function find(string $key): ?VirtualEntry
    {
        foreach ($this->entries as $entry) {
            if (hash_equals(EntryKey::of($entry->row()), $key)) {
                return $entry;
            }
        }

        return null;
    }
}
