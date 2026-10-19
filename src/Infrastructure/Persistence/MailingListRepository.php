<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\MailingList\MailingList;
use Mailadmin\Domain\MailingList\MailingListName;
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
     * @throws PersistenceError
     */
    public function find(MailingListName $name): ?MailingList;

    /**
     * @return bool `false` when a list of that name already exists — the name is
     *              the primary key, so this is a conflict, not a failure
     *
     * @throws PersistenceError
     */
    public function add(MailingList $list): bool;

    /**
     * Overwrite `tag`, `address` and `members` of an existing row.
     *
     * The name is the primary key and is **not** changed here: renaming a list
     * is a delete and a create, so that the URL of a list and the key the
     * configuration management reads never disagree about which list is meant
     * (U-60).
     *
     * @throws PersistenceError
     */
    public function replace(MailingList $list): void;

    /**
     * @return bool `false` when there was no such row
     *
     * @throws PersistenceError
     */
    public function remove(MailingListName $name): bool;
}
