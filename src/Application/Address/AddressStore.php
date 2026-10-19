<?php

declare(strict_types=1);

namespace Mailadmin\Application\Address;

use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\Virtual\VirtualRow;
use Mailadmin\Infrastructure\Persistence\PersistenceError;

/**
 * Everything A3 does to the `virtual` table, and the little it reads from
 * `accountuser` to classify a row (architecture 3.4).
 *
 * **Rows, not entries.** The store hands out and takes {@see VirtualRow}s exactly
 * as stored; what a row *means* is decided once, by
 * `VirtualEntryClassifier`, in the use case. A store that classified would be a
 * second place deciding the meaning of a field pattern.
 *
 * **There is no key.** `virtual` has no primary key and no unique index, so a
 * row is identified by all four of its fields, compared byte for byte — the way
 * {@see remove()} and {@see count()} do it. Two identical rows are one row
 * twice; {@see remove()} takes one of them.
 *
 * **MyISAM** (O-11): {@see atomically()} groups the statements with their audit
 * entry, but a statement that ran is not rolled back. The use cases set the
 * previous rows back when the audit entry fails (U-105).
 *
 * The SQL lives in {@see \Mailadmin\Infrastructure\Persistence\VirtualRepository};
 * the use-case tests run against
 * {@see \Mailadmin\Infrastructure\Persistence\InMemoryVirtualStore}.
 */
interface AddressStore
{
    /**
     * Every row that belongs to `$domain` (U-103):
     *
     *  1. `alias` ends in `@<domain>` — addresses, aliases, the catchall, and
     *     the forwards of `fqun` accounts — compared without regard to case,
     *     the way Postfix looks them up;
     *  2. `alias` has no `@` and is, without regard to case, the stored user
     *     name of an account of the domain, whatever `username` holds — the
     *     forwards of `dotted` accounts, and anything else under the key
     *     Postfix routes that account's mail by (U-126);
     *  3. `alias` has no `@` and names no account at all, `username` is not
     *     empty, and `username` is an account of the domain — rows that fit
     *     no pattern but grant the domain's account something. A key that
     *     names an account of **another** domain belongs there, under 2.
     *
     * Ordered by `alias`, then the remaining fields, so that a page is stable.
     *
     * @return list<VirtualRow>
     *
     * @throws PersistenceError
     */
    public function rowsOfDomain(DomainName $domain): array;

    /**
     * Every row whose `alias` equals `$alias` without regard to case — exactly
     * the rows Postfix's `virtual_alias_maps` query returns for the key, plus
     * the disabled ones.
     *
     * @return list<VirtualRow>
     *
     * @throws PersistenceError
     */
    public function rowsWithAlias(string $alias): array;

    /**
     * The forward rows of one account: `alias` byte-equal to the stored user
     * name and `username` empty — the rule `AccountRepository::delete()` uses.
     *
     * @return list<VirtualRow>
     *
     * @throws PersistenceError
     */
    public function forwardRowsOf(string $storedUsername): array;

    /**
     * `accountuser.domain_name` for those of `$usernames` that are accounts,
     * keyed by the user name **as stored**. Matched without regard to case, so
     * that a destination typed in another case still finds its account.
     *
     * Rows whose `domain_name` is not a domain name (the Cyrus administrator's)
     * are left out.
     *
     * @param list<string> $usernames
     *
     * @return array<string, DomainName>
     *
     * @throws PersistenceError
     */
    public function accountDomains(array $usernames): array;

    /**
     * Is the address in use — as any row's `alias`, or as an account's stored
     * user name (`fqun`)? Without regard to case, because Postfix and Cyrus
     * do not regard it either.
     *
     * @throws PersistenceError
     */
    public function addressTaken(string $address): bool;

    /**
     * Is `$name` the stored user name of an account, without regard to case?
     *
     * @throws PersistenceError
     */
    public function isAccountName(string $name): bool;

    /**
     * @throws PersistenceError
     */
    public function insert(VirtualRow $row): void;

    /**
     * Remove one row with exactly these four fields. `false` when there was
     * none.
     *
     * @throws PersistenceError
     */
    public function remove(VirtualRow $row): bool;

    /**
     * How many rows have exactly these four fields.
     *
     * @throws PersistenceError
     */
    public function count(VirtualRow $row): int;

    /**
     * Run `$work` in one transaction — the write and its audit entry (U-63).
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws \Throwable
     */
    public function atomically(callable $work): mixed;
}
