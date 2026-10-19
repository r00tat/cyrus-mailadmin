<?php

declare(strict_types=1);

namespace Mailadmin\Application\Account;

use Mailadmin\Domain\Model\AccountServices;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\MailboxIdentity;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Page;
use Mailadmin\Infrastructure\Persistence\PersistenceError;

/**
 * Everything A2's use cases do to the database: `accountuser` and the rows of
 * `virtual` that belong to an account.
 *
 * In the application layer for the reason {@see \Mailadmin\Application\Domain\DomainStore}
 * gives — paging is a persistence concept. The SQL lives in
 * {@see \Mailadmin\Infrastructure\Persistence\AccountRepository}; the use-case
 * tests run against {@see \Mailadmin\Infrastructure\Persistence\InMemoryAccountStore}.
 *
 * **Both legacy tables are MyISAM** (O-11). {@see atomically()} groups the
 * statements and the audit row, but a MyISAM statement that already ran is not
 * rolled back. That is why the ordering of architecture 8.1 is the real
 * guarantee and the transaction only the second one.
 */
interface AccountStore
{
    /**
     * One page of the accounts of `$domain`. Not scoped: the use case has
     * already decided that the actor may see the domain, and a single domain is
     * the whole scope of this listing.
     *
     * @return Page<AccountRecord>
     *
     * @throws PersistenceError
     * @throws \Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError
     */
    public function list(DomainName $domain, ListCriteria $criteria): Page;

    /**
     * The row with exactly this stored user name, or `null`.
     *
     * A row whose `domain_name` is not a domain name at all — the Cyrus
     * administrator's own row has an empty one — is `null` as well: it is not an
     * account a domain is responsible for.
     *
     * @throws PersistenceError
     */
    public function find(string $storedUsername): ?AccountRecord;

    /**
     * @throws PersistenceError
     */
    public function countIn(DomainName $domain): int;

    /**
     * The stored user names of the domain — for the sum of quota limits (U-81).
     *
     * @return list<string>
     *
     * @throws PersistenceError
     */
    public function usernamesIn(DomainName $domain): array;

    /**
     * Is the user name in `accountuser`, the address or the user name in
     * `virtual.alias`, or the user name in `virtual.username`? Compared
     * case-insensitively, because Cyrus and Postfix do.
     *
     * `virtual.username` counts because `validsenders` joins it against
     * `accountuser`: a row that names the user name there — an alias's
     * placeholder sender (U-106) or an earlier account's leftover — would hand
     * its sending right to the new account (U-110).
     *
     * @throws PersistenceError
     */
    public function isTaken(MailboxIdentity $identity): bool;

    /**
     * The database half of a creation: `accountuser` and the address row in
     * `virtual` (`alias` = address, `dest` = `username` = Cyrus user name,
     * `status` = 1), in one transaction. A leftover row in
     * `mailadmin_account_status` under the same name — an account webcyradm
     * deleted — is removed, so that a new account never starts disabled.
     *
     * @throws PersistenceError
     */
    public function create(
        MailboxIdentity $identity,
        string $passwordHash,
        AccountServices $services,
        string $legacyPrefix,
    ): void;

    /**
     * @throws PersistenceError
     */
    public function updateServices(string $storedUsername, AccountServices $services): void;

    /**
     * Enable or disable the account's logins: its row in
     * `mailadmin_account_status` (U-127). `$changedBy` is the administrator.
     *
     * Disabling records when and by whom; enabling keeps the row with
     * `disabled = 0`. The table is InnoDB, so unlike `accountuser` this write
     * is rolled back with its transaction.
     *
     * @throws PersistenceError
     */
    public function setStatus(string $storedUsername, bool $enabled, string $changedBy): void;

    /**
     * Does the view `allusers` — what `pam_mysql` reads — offer a login under
     * the stored user name or the address? Read in the caller's transaction,
     * so that a status written a moment ago and not yet committed counts.
     *
     * This is how {@see UpdateAccount} proves that a disabled account really
     * is locked out before it says so (U-127): an installation still running
     * the view without the status filter answers `true` here.
     *
     * @throws PersistenceError
     */
    public function offersLogin(string $storedUsername, string $address): bool;

    /**
     * Write a hash. Never a plaintext — the parameter name is the contract.
     *
     * @throws PersistenceError
     */
    public function updatePasswordHash(string $storedUsername, string $passwordHash): void;

    /**
     * Remove the account's rows: the `virtual` rows **of its own domain** that
     * name it as `username`, the forward rows whose `alias` is its user name,
     * the `accountuser` row (U-83) and its row in `mailadmin_account_status`
     * (U-127). Returns how many `virtual` rows went with it.
     *
     * A row of another domain that names the account as its sender — a
     * catchall or alias there may (U-106) — is left exactly as it is (U-129):
     * the deletion is the actor's business in `$domain` only, and removing
     * the row would take the other domain's destinations with it. "Of its own
     * domain" is decided the way U-126 decides it: by the part after the `@`,
     * and for a key without one by the domain of the account the key names.
     *
     * @param DomainName $domain `accountuser.domain_name` of the account
     *
     * @throws PersistenceError
     */
    public function delete(string $storedUsername, DomainName $domain): int;

    /**
     * Run `$work` while holding the per-domain account lock (U-81).
     *
     * The lock is what makes "count, compare, insert" safe against a second
     * request doing the same: two creations in one domain run one after the
     * other. It is not a lock against webcyradm, which knows nothing of it.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws PersistenceError when the lock cannot be taken in time
     * @throws \Throwable       whatever `$work` throws
     */
    public function withDomainLock(DomainName $domain, callable $work): mixed;

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
