<?php

declare(strict_types=1);

namespace Mailadmin\Application\Domain;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Model\Domain;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Page;
use Mailadmin\Infrastructure\Persistence\PersistenceError;

/**
 * Everything A1's use cases do to the database.
 *
 * A port, so that the use cases — and above all the rename, whose failure paths
 * are the reason this package is careful — can be exercised against
 * {@see \Mailadmin\Infrastructure\Persistence\InMemoryDomainStore} without a
 * MariaDB. The SQL lives in
 * {@see \Mailadmin\Infrastructure\Persistence\DomainRepository}.
 *
 * It sits in the **application** layer and not in `Domain\`, unlike the mail
 * store and the administrator directory. The reason is {@see list()}: paging and
 * ordering are {@see ListCriteria} and {@see Page}, which are persistence
 * concepts, and a domain-layer interface may not import them (architecture 3,
 * and `tests/Unit/Domain/LayeringTest.php`). Moving paging into the domain model
 * to satisfy a file location would be the tail wagging the dog.
 *
 * ## What is not here
 *
 * No `save()` that decides between insert and update, and no method that renames
 * by writing a `Domain` with a different name. The two are different operations
 * with different failure modes — a rename reaches Cyrus first (architecture
 * 8.1), an update does not — and an interface that let one be mistaken for the
 * other would put that distinction at the mercy of a caller's attention.
 */
interface DomainStore
{
    /**
     * One page of the domains `$actor` may see.
     *
     * The narrowing is the implementation's job, through
     * `Repository::scopedTo()` (U-45). A caller does not filter afterwards, and
     * there is no variant of this method that skips it.
     *
     * @return Page<DomainSummary>
     *
     * @throws PersistenceError
     * @throws \Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError when the request
     *                                                                     names a field the
     *                                                                     resource does not offer
     */
    public function list(ActorContext $actor, ListCriteria $criteria): Page;

    /**
     * One domain, or `null` when there is no such row.
     *
     * **Not scoped.** Visibility is the policy's decision and it has to be made
     * before the lookup, so that a foreign domain and a missing one are refused
     * for the reason each deserves (U-44). A store that filtered here would make
     * both answers the same by accident rather than by design, and would take
     * the choice away from the use case.
     *
     * @throws PersistenceError
     */
    public function find(DomainName $name): ?DomainSummary;

    /**
     * @throws PersistenceError
     */
    public function exists(DomainName $name): bool;

    /**
     * Is this value already in the unique `domain.prefix` column (U-46)?
     *
     * @throws PersistenceError
     */
    public function prefixTaken(string $prefix): bool;

    /**
     * Insert the row, and the settings row when there is something to say.
     *
     * @throws PersistenceError
     */
    public function create(Domain $domain): void;

    /**
     * Update the row identified by `$domain->name`.
     *
     * **Never renames.** `domain.prefix` is not written either: it is assigned
     * once, at creation (U-46).
     *
     * @throws PersistenceError
     */
    public function update(Domain $domain): void;

    /**
     * Remove the `domain` row, its settings row and the `domainadmin` rows that
     * assign it (U-95). Returns the administrators whose assignment went, in a
     * stable order, so that the audit trail can name them.
     *
     * The assignments go **first**: under MyISAM (O-11) a failure part way
     * leaves either a domain nobody is assigned to — which a superuser can
     * repair — or nothing changed. The other order could leave the assignment of
     * a domain that no longer exists, and that is the state that hands a
     * re-created domain to its old domain master.
     *
     * The wildcard row `*` names no domain and is not touched.
     *
     * @return list<string>
     *
     * @throws PersistenceError
     */
    public function delete(DomainName $name): array;

    /**
     * How many `virtual` rows belong to the domain: every row whose `alias`
     * ends in `@<domain>` — addresses, forwards and the catchall `@<domain>`.
     *
     * Compared case-insensitively and without `LIKE`, so that a `_` in a
     * legacy domain name is a character and not a wildcard — the rule
     * {@see rename()} follows for the same column.
     *
     * @throws PersistenceError
     */
    public function addressCount(DomainName $name): int;

    /**
     * The stored user name of an account that already lives in the namespace
     * `$name` would occupy in the dotted scheme, or `null` (U-96).
     *
     * That is an account of a **parent** of `$name` whose user name has no `@`
     * and ends in `.<name>` — `info.kunde.example.de` of `example.de` for
     * `kunde.example.de`. Compared case-insensitively. The accounts of
     * `$except` are left out: a rename moves them along.
     *
     * A mailing list of such a parent claims the namespace the same way, by
     * its key `REPLACE(address, '@', '.')` (U-145): `info.kunde@example.de` is
     * what the account `info` of `kunde.example.de` would be called. Its
     * address is returned then.
     *
     * @throws PersistenceError
     */
    public function namespaceClaimant(DomainName $name, ?DomainName $except = null): ?string;

    /**
     * Is this user name in `accountuser`, compared case-insensitively, among
     * the accounts that do not belong to `$except`?
     *
     * For a rename: the accounts of the domain being renamed move themselves,
     * so their old names are not in the way.
     *
     * @throws PersistenceError
     */
    public function accountUsernameTaken(string $username, DomainName $except): bool;

    /**
     * Is there a row in `virtual` whose `alias` is exactly this value,
     * compared case-insensitively?
     *
     * For a rename (U-101): a forward is keyed by the Cyrus user name
     * (architecture 3.3), and one left over from an earlier account would
     * apply to a moved account that lands on that name — the rule
     * `AccountStore::isTaken()` applies to a new account.
     *
     * @throws PersistenceError
     */
    public function aliasTaken(string $alias): bool;

    /**
     * Is there a row in `virtual` whose `username` is this value, compared
     * case-insensitively?
     *
     * For a rename (U-110): `validsenders` joins `virtual.username` against
     * `accountuser`, so a moved account landing on a name some row carries
     * there — an alias placeholder (U-106), an earlier account's leftover —
     * would gain that row's sending right. The rule `AccountStore::isTaken()`
     * applies to a new account.
     *
     * @throws PersistenceError
     */
    public function senderNameTaken(string $username): bool;

    /**
     * How many `domainadmin` rows name this domain, compared
     * case-insensitively (U-99). For a name that is not a domain they are
     * leftovers, and they would apply to a domain created under it.
     *
     * @throws PersistenceError
     */
    public function assignmentCount(DomainName $name): int;

    /**
     * Run `$work` while holding the namespace lock (U-97).
     *
     * One lock for everything that adds a name to the namespace of Cyrus user
     * names: the database step of an account creation, and domain creation,
     * rename and deletion. The ambiguity checks of U-86 and U-96 read one
     * table and protect the other; only a lock both sides take makes "check,
     * then write" safe against the other side doing the same.
     *
     * Re-entrant on the same connection, ten seconds like the account lock
     * (U-81). A lock that is not free in time is a {@see PersistenceError}.
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
    public function withNamespaceLock(callable $work): mixed;

    /**
     * The stored `accountuser.username` values of the domain, in a stable order.
     *
     * Verbatim, because they are what Cyrus knows the mailboxes as and what the
     * rename has to move (architecture 3.3).
     *
     * @return list<string>
     *
     * @throws PersistenceError
     */
    public function accountUsernames(DomainName $domain): array;

    /**
     * The database half of a rename, in one transaction.
     *
     * Called **after** every mailbox has been renamed in Cyrus, never before
     * (architecture 8.1). It updates `domain`, `accountuser`, `virtual`,
     * `domainadmin` and `mailadmin_domain_setting`. In `virtual` that includes
     * the forward rows keyed by a moved account's old user name (U-101).
     *
     * The status rows of the moved accounts (U-127) are carried to the new
     * names first and removed from the old ones last; see
     * {@see carryAccountStatus()} for why the caller carries them beforehand
     * as well.
     *
     * @param array<string, string> $renamedAccounts old `accountuser.username`
     *                                               => the name it now has; the
     *                                               caller derived them through
     *                                               {@see \Mailadmin\Domain\Model\MailboxIdentity}
     *
     * @throws PersistenceError
     */
    public function rename(DomainName $from, DomainName $to, array $renamedAccounts): void;

    /**
     * Give every new user name the status row its old name has, or none
     * (U-129). The old rows stay; {@see rename()} removes them.
     *
     * Called **outside** the transaction of {@see rename()}, so that it
     * commits on its own. `accountuser` is MyISAM (O-11): a rename that fails
     * after its `UPDATE` leaves the account under the new name while InnoDB
     * rolls back. With the status under both names beforehand, a disabled
     * account stays disabled whichever name it ends up with, and there is no
     * moment between two statements in which it could log in. A row left
     * under a new name by an account deleted outside mailadmin is removed
     * when the moving account has none, so it cannot lock that account out.
     *
     * @param array<string, string> $renamedAccounts as for {@see rename()}
     *
     * @throws PersistenceError
     */
    public function carryAccountStatus(array $renamedAccounts): void;

    /**
     * Run `$work` in one transaction.
     *
     * For the audit entry, not for the writes above — each of those is already
     * its own transaction. A use case wraps its write and the
     * {@see \Mailadmin\Application\Audit\AuditWriter::record()} call in this, so
     * that the `mailadmin_audit` row and the change commit together and a
     * failing audit write takes the change with it (U-63). Nested transactions
     * are counted by the connection, so the inner `transactional()` of
     * {@see create()} or {@see rename()} joins this one rather than committing
     * on its own.
     *
     * Named `atomically` for the reason `AdminStore` gives: the SQL
     * implementation extends `Repository`, whose `transactional()` hands the
     * callable a connection.
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
