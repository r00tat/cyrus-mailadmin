<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\MailingList\MailingList;
use Mailadmin\Domain\Model\DomainName;
use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterAllowlist;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterComparison;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterField;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortAllowlist;

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
 *
 * ## The identity is the address
 *
 * Production `lists` has an AUTO_INCREMENT `id` and no unique index at all, so
 * `name` is only a display text and may repeat. The address identifies a list;
 * that it is unique is the use cases' job, because the database cannot say so.
 * Old data may still hold an address twice: {@see find()} then takes the lowest
 * `id`, and {@see remove()} deletes all of them.
 */
final class SqlMailingListRepository extends Repository implements MailingListRepository
{
    /**
     * The four columns, and the domain lifted out of `address` so that the scope
     * predicate has an identifier to bind against.
     */
    private const string SOURCE = '(SELECT `id`, `name`, `tag`, `address`, `members`, '
        . "SUBSTRING_INDEX(`address`, '@', -1) AS `list_domain` FROM `lists`) AS `ml`";

    protected function sortable(): SortAllowlist
    {
        // `address` is the identity, so it is also the tiebreaker: sorting by a
        // tag that many rows share would otherwise leave the order of equals to
        // the server, and a row could appear on two pages. (The database cannot
        // promise it is unique; the use cases do.)
        return new SortAllowlist(
            [
                'name' => 'ml.name',
                'tag' => 'ml.tag',
                'address' => 'ml.address',
            ],
            default: 'name',
            tiebreaker: 'address',
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

    public function find(EmailAddress $address): ?MailingList
    {
        // Lowest `id` first: with a duplicate address from old data the choice
        // must not be left to the server.
        $row = $this->connection->selectOne(
            'SELECT `name`, `tag`, `address`, `members` FROM `lists` '
            . 'WHERE `address` = :address ORDER BY `id` LIMIT 1',
            ['address' => $address->toString()],
        );

        return $row === null ? null : self::toMailingList($row);
    }

    public function existsWithKey(string $listKey): bool
    {
        return $this->connection->selectOne(
            "SELECT 1 AS `found` FROM `lists` WHERE REPLACE(`address`, '@', '.') = :list_key LIMIT 1",
            ['list_key' => $listKey],
        ) !== null;
    }

    /**
     * Matched with `RIGHT()` rather than `LIKE`, as `DomainRepository` does for
     * `virtual.alias`: a `_` or `%` in the domain cannot turn into a wildcard.
     * The column's collation ignores case; `LOWER()` makes the result
     * lower-case whatever was stored, and `TRIM()` matches what the classifier
     * compares against (a trimmed `alias`).
     */
    public function addressesInDomain(DomainName $domain): array
    {
        $suffix = '@' . strtolower($domain->toString());
        $length = strlen($suffix);

        // One placeholder per occurrence: native prepares refuse a name used twice.
        $rows = $this->connection->select(
            'SELECT DISTINCT LOWER(TRIM(`address`)) AS `address` FROM `lists` '
            . 'WHERE CHAR_LENGTH(TRIM(`address`)) > :min AND LOWER(RIGHT(TRIM(`address`), :tail)) = :suffix',
            ['min' => $length, 'tail' => $length, 'suffix' => $suffix],
        );

        return array_values(array_map(
            static fn (array $row): string => self::text($row['address'] ?? null),
            $rows,
        ));
    }

    public function add(MailingList $list): void
    {
        $this->connection->execute(
            'INSERT INTO `lists` (`name`, `tag`, `address`, `members`) '
            . 'VALUES (:name, :tag, :address, :members)',
            self::bindings($list),
        );
    }

    public function replace(MailingList $list): void
    {
        // No `rowCount()` check: MariaDB reports zero affected rows for an UPDATE
        // that sets a row to the values it already has, so a check here would
        // report "no such list" for the most ordinary case there is. Whether the
        // row exists is settled by the use case, which has to load it anyway for
        // the ETag.
        $this->connection->execute(
            'UPDATE `lists` SET `name` = :name, `tag` = :tag, `members` = :members '
            . 'WHERE `address` = :address',
            self::bindings($list),
        );
    }

    public function remove(EmailAddress $address): bool
    {
        return $this->connection->execute(
            'DELETE FROM `lists` WHERE `address` = :address',
            ['address' => $address->toString()],
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
            // Verbatim. This is the promise the interface makes. The production
            // column is `NOT NULL`, so a row read as `null` goes back as ''.
            'members' => $list->members->toStoredValue() ?? '',
        ];
    }

    /**
     * @param array<string, mixed> $row
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
}
