<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Exception\InvalidMailingList;
use Mailadmin\Domain\MailingList\MailingList;
use Mailadmin\Domain\MailingList\MailingListName;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterAllowlist;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterComparison;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterField;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortAllowlist;
use PDOException;

/**
 * `lists`, over PDO (A5).
 *
 * ## Why the listing reads from a derived table
 *
 * `lists` has four columns and none of them is a domain: the only place a domain
 * appears is inside `address`. Scoping a listing to an administrator's domains
 * therefore needs an expression, and {@see Criteria\ColumnName} refuses one on
 * purpose — an allowlist entry may name a column and nothing else.
 *
 * The way out is the one that class documents: compute the value under an alias
 * and put the alias in the allowlist. So the listing selects
 * `SUBSTRING_INDEX(address, '@', -1) AS list_domain` in a derived table, and the
 * scope predicate is an ordinary `IN` over an identifier again.
 *
 * That matters even though **nothing is scoped today**: `list.*` is superuser
 * only (U-58), so {@see Repository::scopedTo()} is a no-op for every actor who
 * can reach this code. Writing it as a no-op that will start working, rather
 * than as an `if` somebody has to remember to add, is exactly the point of
 * `scopedTo()` (U-45) — widening the permission later is then a change in
 * `RolePermissions` and `ListPolicy`, with nothing to remember here.
 *
 * ## The storage engine caveat
 *
 * `lists` is MyISAM in the legacy schema, so a transaction over it commits and
 * rolls back nothing (O-11). Every write here is therefore a **single
 * statement** — there is no multi-statement write in this repository that would
 * quietly depend on atomicity it does not have.
 */
final class SqlMailingListRepository extends Repository implements MailingListRepository
{
    /** SQLSTATE for an integrity constraint violation — here, the primary key. */
    private const string SQLSTATE_INTEGRITY_VIOLATION = '23000';

    /**
     * The four columns, and the domain lifted out of `address` so that the scope
     * predicate has an identifier to bind against.
     */
    private const string SOURCE = '(SELECT `name`, `tag`, `address`, `members`, '
        . "SUBSTRING_INDEX(`address`, '@', -1) AS `list_domain` FROM `lists`) AS `ml`";

    protected function sortable(): SortAllowlist
    {
        // `name` is the primary key, so it is also the tiebreaker: sorting by a
        // tag that 22 rows share would otherwise leave the order of equals to the
        // server, and a row could appear on two pages.
        return new SortAllowlist(
            [
                'name' => 'ml.name',
                'tag' => 'ml.tag',
                'address' => 'ml.address',
            ],
            default: 'name',
        );
    }

    protected function filterable(): FilterAllowlist
    {
        return new FilterAllowlist(
            new FilterField('name', 'ml.name', FilterComparison::Contains),
            new FilterField('tag', 'ml.tag', FilterComparison::Equals),
            new FilterField('address', 'ml.address', FilterComparison::Contains),
            // "Which lists deliver to this address?" — a substring match over the
            // members column, which is the only way to ask it without a second
            // table. It can match across a separator; the contract says so.
            new FilterField('member', 'ml.members', FilterComparison::Contains),
        );
    }

    public function list(ActorContext $actor, ListCriteria $criteria): Page
    {
        $query = new ListQuery(
            select: '`ml`.`name`, `ml`.`tag`, `ml`.`address`, `ml`.`members`',
            from: self::SOURCE,
        );

        return $this->fetchPage(
            $this->scopedTo($query, $actor, 'ml.list_domain'),
            $criteria,
            self::toMailingList(...),
        );
    }

    public function find(MailingListName $name): ?MailingList
    {
        $row = $this->connection->selectOne(
            'SELECT `name`, `tag`, `address`, `members` FROM `lists` WHERE `name` = :name',
            ['name' => $name->toString()],
        );

        return $row === null ? null : self::toMailingList($row);
    }

    public function add(MailingList $list): bool
    {
        try {
            $this->connection->execute(
                'INSERT INTO `lists` (`name`, `tag`, `address`, `members`) '
                . 'VALUES (:name, :tag, :address, :members)',
                self::bindings($list),
            );
        } catch (PersistenceError $error) {
            if (self::isDuplicateKey($error)) {
                return false;
            }

            throw $error;
        }

        return true;
    }

    public function replace(MailingList $list): void
    {
        // No `rowCount()` check: MariaDB reports zero affected rows for an UPDATE
        // that sets a row to the values it already has, so a check here would
        // report "no such list" for the most ordinary case there is. Whether the
        // row exists is settled by the use case, which has to load it anyway for
        // the ETag.
        $this->connection->execute(
            'UPDATE `lists` SET `tag` = :tag, `address` = :address, `members` = :members '
            . 'WHERE `name` = :name',
            self::bindings($list),
        );
    }

    public function remove(MailingListName $name): bool
    {
        return $this->connection->execute(
            'DELETE FROM `lists` WHERE `name` = :name',
            ['name' => $name->toString()],
        ) > 0;
    }

    /**
     * @return array<string, string|null>
     */
    private static function bindings(MailingList $list): array
    {
        return [
            'name' => $list->name->toString(),
            'tag' => $list->tag,
            'address' => $list->address,
            // Verbatim, `null` included. This is the promise the interface makes.
            'members' => $list->members->toStoredValue(),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws InvalidMailingList when the stored name is unusable as a key —
     *                            empty, or carrying whitespace or a colon
     */
    private static function toMailingList(array $row): MailingList
    {
        /** @var mixed $members */
        $members = $row['members'] ?? null;

        return MailingList::fromStoredValues(
            name: self::text($row['name'] ?? null),
            tag: self::text($row['tag'] ?? null),
            address: self::text($row['address'] ?? null),
            members: is_scalar($members) ? (string) $members : null,
        );
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * A primary-key collision, told apart from every other statement failure.
     *
     * The driver's error is wrapped by {@see Connection}, which deliberately
     * keeps the values out of the message — so the classification is made on the
     * SQLSTATE of the original {@see PDOException}, not on its text.
     */
    private static function isDuplicateKey(PersistenceError $error): bool
    {
        $previous = $error->getPrevious();

        if (!$previous instanceof PDOException) {
            return false;
        }

        /** @var mixed $sqlState */
        $sqlState = $previous->errorInfo[0] ?? $previous->getCode();

        return is_scalar($sqlState) && (string) $sqlState === self::SQLSTATE_INTEGRITY_VIOLATION;
    }
}
