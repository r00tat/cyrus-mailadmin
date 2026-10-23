<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\MailingList\MailingList;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;

/**
 * Reading and writing `lists` (A5).
 *
 * An interface rather than a class for the same reason
 * {@see \Mailadmin\Infrastructure\Security\RefreshTokenStore} is one: the use
 * cases in `Application\MailingList` are then testable against
 * {@see InMemoryMailingListRepository} without a MariaDB, and the SQL is tested
 * once, against a real one.
 *
 * It lives here rather than in `Domain` because a listing returns a {@see Page},
 * and the domain model does not import from an outer layer (architecture 3).
 *
 * ## The one thing every implementation owes
 *
 * **`members` comes back exactly as it went in when nothing changed.** The
 * column is written by an external tool and read by the configuration management
 * (E-4); a save that re-punctuates a row nobody edited would rewrite 22 rows the
 * first time an administrator fixes a typo in a tag.
 * {@see \Mailadmin\Domain\MailingList\MemberList} is what makes that true, and
 * an implementation must store {@see \Mailadmin\Domain\MailingList\MemberList::toStoredValue()}
 * verbatim — including a `null`.
 */
interface MailingListRepository
{
    /**
     * One page of lists, narrowed to what the actor may see.
     *
     * @return Page<MailingList>
     *
     * @throws PersistenceError
     */
    public function list(ActorContext $actor, ListCriteria $criteria): Page;

    /**
     * The list with that address.
     *
     * `lists` has no unique index on `address`, so an old row can exist twice.
     * Then this is the one with the smallest `id`.
     *
     * @throws PersistenceError
     */
    public function find(EmailAddress $address): ?MailingList;

    /**
     * Is there any list whose `REPLACE(address, '@', '.')` equals the key —
     * including one with a different address (`a.b@c` and `a@b.c` share a key)?
     *
     * @throws PersistenceError
     */
    public function existsWithKey(string $listKey): bool;

    /**
     * The addresses of every list in `$domain`, lower-cased — what the
     * classifier of `virtual` needs to tell a legacy list row from an account's
     * address row ({@see \Mailadmin\Domain\Model\Virtual\MailingListRecognition}).
     * One query for a whole domain, not one per row.
     *
     * @return list<string>
     *
     * @throws PersistenceError
     */
    public function addressesInDomain(DomainName $domain): array;

    /**
     * Insert a row; `id` is assigned by the database.
     *
     * There is no unique index, so there is no conflict to report here: that
     * the address and its key are free is the use case's check.
     *
     * @throws PersistenceError
     */
    public function add(MailingList $list): void;

    /**
     * Overwrite `name`, `tag` and `members` of the row(s) with this list's
     * address.
     *
     * The address is the identity and is **not** changed here: changing it is a
     * delete and a create, so that the URL of a list and the key the pipe
     * script reads never disagree about which list is meant (U-60).
     *
     * @throws PersistenceError
     */
    public function replace(MailingList $list): void;

    /**
     * Delete every row with this address — an old duplicate goes with the rest.
     *
     * @return bool `false` when there was no such row
     *
     * @throws PersistenceError
     */
    public function remove(EmailAddress $address): bool;
}
