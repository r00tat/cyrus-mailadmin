<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Infrastructure\Persistence\Criteria\ColumnName;
use Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError;
use Mailadmin\Infrastructure\Persistence\Criteria\FilterAllowlist;
use Mailadmin\Infrastructure\Persistence\Criteria\Filters;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\OrderBy;
use Mailadmin\Infrastructure\Persistence\Criteria\SortAllowlist;
use PDO;
use Throwable;

/**
 * The base every repository extends (A1–A6).
 *
 * It provides three things and withholds a fourth.
 *
 * **1. Paging that produces the contract's `meta` block.** {@see fetchPage()}
 * runs two statements — the rows and the matching count — and returns a
 * {@see Page}. `per_page` defaults to 50 and is capped at 200 (U-20); both live
 * in {@see ListCriteria}, not here.
 *
 * **2. Ordering and filtering that cannot come from a request.**
 * {@see sortable()} is abstract: a repository has to say what it will sort by
 * before it can list anything. `fetchPage()` takes the raw
 * {@see ListCriteria} and resolves it against that allowlist itself — there is
 * no parameter through which a subclass could pass an ordering of its own, and
 * {@see OrderBy} cannot be constructed any other way. A field that is not in
 * the list raises an error; it is never ignored. This is the one thing the old
 * system got wrong in the way that mattered (`CLAUDE.md`, rule 2).
 *
 * **3. One way to open a transaction.** {@see transactional()} delegates to
 * {@see Connection::transactional()} and re-enters, so nesting repositories
 * inside a use case produces one transaction.
 *
 * **What it withholds is a generic `find`, `save` or `delete`.** The legacy
 * schema has no surrogate keys, a table that carries four different meanings
 * and two spellings for one account; every statement is worth reading. That is
 * also why there is no ORM (architecture 3.2).
 *
 * ## Writing one
 *
 * ```php
 * final class DomainRepository extends Repository
 * {
 *     protected function sortable(): SortAllowlist
 *     {
 *         return new SortAllowlist(
 *             ['name' => 'd.domain_name', 'max_accounts' => 'd.maxaccounts'],
 *             default: 'name',
 *         );
 *     }
 *
 *     protected function filterable(): FilterAllowlist
 *     {
 *         return new FilterAllowlist(
 *             new FilterField('name', 'd.domain_name', FilterComparison::Contains),
 *         );
 *     }
 *
 *     public function list(ActorContext $actor, ListCriteria $criteria): Page
 *     {
 *         $query = new ListQuery(
 *             select: 'd.domain_name, d.maxaccounts, d.quota',
 *             from: '`domain` d',
 *         );
 *
 *         return $this->fetchPage(
 *             $this->scopedTo($query, $actor, 'd.domain_name'),
 *             $criteria,
 *             $this->toDomain(...),
 *         );
 *     }
 * }
 * ```
 *
 * The scope predicate is built by the repository, not by the request, so it
 * goes into the {@see ListQuery} rather than into the filters.
 *
 * **Use {@see scopedTo()} rather than {@see restrictToDomains()}** (S3). The
 * latter needs an `if ($actor->requiresDomainScope())` around it, and that `if`
 * is the whole exposure: forget it and every listing quietly widens.
 */
abstract class Repository
{
    /** Placeholders the base generates. See {@see ListQuery} for the collision check. */
    private const string LIMIT = FilterAllowlist::PLACEHOLDER_PREFIX . 'limit';
    private const string OFFSET = FilterAllowlist::PLACEHOLDER_PREFIX . 'offset';
    private const string SCOPE = FilterAllowlist::PLACEHOLDER_PREFIX . 'scope';

    public function __construct(protected readonly Connection $connection)
    {
    }

    /**
     * What this repository will sort by, and what it sorts by when the request
     * says nothing.
     *
     * Abstract because a listing without a declared ordering is the defect this
     * class exists to prevent. The keys have to match the `sort` enum the
     * contract declares for the resource; a contract test in the owning package
     * should compare the two.
     */
    abstract protected function sortable(): SortAllowlist;

    /**
     * What this repository will filter by. Nothing, unless it says otherwise.
     */
    protected function filterable(): FilterAllowlist
    {
        return FilterAllowlist::none();
    }

    /**
     * One page of `$query`, ordered and filtered according to `$criteria`.
     *
     * @template T
     *
     * @param callable(array<string, mixed>): T $map row => domain object
     *
     * @return Page<T>
     *
     * @throws PersistenceError
     */
    protected function fetchPage(ListQuery $query, ListCriteria $criteria, callable $map): Page
    {
        $filters = $this->filterable()->compile($criteria->filters);
        $order = OrderBy::fromAllowlist($this->sortable(), $criteria->sort, $criteria->order);

        self::assertNoBindingCollision($query, $filters);

        $total = $this->countMatching($query, $filters);
        if ($total === 0) {
            return Page::of([], $criteria, 0);
        }

        $sql = sprintf(
            'SELECT %s FROM %s%s%s %s LIMIT :%s OFFSET :%s',
            $query->select,
            $query->from,
            self::whereClause($query, $filters),
            $query->groupBy === null ? '' : ' GROUP BY ' . $query->groupBy,
            $order->toSql(),
            self::LIMIT,
            self::OFFSET,
        );

        $rows = $this->connection->select($sql, [
            ...$query->bindings,
            ...$filters->bindings,
            self::LIMIT => $criteria->perPage,
            self::OFFSET => $criteria->offset(),
        ]);

        return Page::of(array_map($map, $rows), $criteria, $total);
    }

    /**
     * Every row of `$query`, ordered but not paged.
     *
     * For the cases where a page makes no sense — the domains a domain master
     * may see, the addresses of one account. It still goes through the sort
     * allowlist, because "unordered" is not a thing a caller should be able to
     * ask for by omission.
     *
     * @template T
     *
     * @param callable(array<string, mixed>): T $map
     *
     * @return list<T>
     *
     * @throws PersistenceError
     */
    protected function fetchAll(ListQuery $query, callable $map, ?string $sort = null): array
    {
        $order = OrderBy::fromAllowlist($this->sortable(), $sort);

        $sql = sprintf(
            'SELECT %s FROM %s%s%s %s',
            $query->select,
            $query->from,
            self::whereClause($query, Filters::none()),
            $query->groupBy === null ? '' : ' GROUP BY ' . $query->groupBy,
            $order->toSql(),
        );

        return array_map($map, $this->connection->select($sql, $query->bindings));
    }

    /**
     * Narrow a listing to a set of domains — the shape every domain-master
     * restriction takes (architecture 6).
     *
     * An empty set produces `1 = 0` rather than no predicate at all. The
     * difference matters: an administrator with no domains must see nothing, and
     * "no predicate" would show them everything.
     *
     * **Prefer {@see scopedTo()}**, which reads the set off the actor and knows
     * when there is nothing to narrow. This one stays for the cases where the
     * set is not simply the actor's own — a superuser listing one domain's
     * accounts, for instance.
     *
     * @param list<string> $domains
     */
    protected function restrictToDomains(ListQuery $query, array $domains, string $column): ListQuery
    {
        $name = ColumnName::parse('scope', $column);

        if ($domains === []) {
            return $query->andWhere('1 = 0');
        }

        $placeholders = [];
        $bindings = [];

        foreach (array_values($domains) as $index => $domain) {
            $placeholder = self::SCOPE . '_' . $index;
            $placeholders[] = ':' . $placeholder;
            $bindings[$placeholder] = $domain;
        }

        return $query->andWhere(
            $name->quoted() . ' IN (' . implode(', ', $placeholders) . ')',
            $bindings,
        );
    }

    /**
     * The same, read straight off the actor — **the form A1 to A6 should use** (S3).
     *
     * {@see restrictToDomains()} leaves the caller holding a boolean:
     *
     * ```php
     * if ($actor->requiresDomainScope()) {
     *     $query = $this->restrictToDomains($query, $actor->visibleDomains(), 'd.domain_name');
     * }
     * ```
     *
     * That `if` is the whole exposure. Forget it and a superuser's listing still
     * works, a domain master's listing still works — it just also contains
     * everybody else's rows, and nothing fails. Forget it here and the
     * unrestricted case is handled by this method, which is the difference
     * between a rule one has to remember and a rule one has to work around.
     *
     * It takes the {@see ActorContext} rather than a list of names for the same
     * reason: a list can be the wrong list.
     */
    protected function scopedTo(ListQuery $query, ActorContext $actor, string $column): ListQuery
    {
        if (!$actor->requiresDomainScope()) {
            return $query;
        }

        return $this->restrictToDomains($query, $actor->scope->domainNames(), $column);
    }

    /**
     * Run `$work` inside a transaction (architecture 8.1).
     *
     * **The mailstore call belongs outside.** A transaction cannot reach Cyrus,
     * so the order around it is what protects delivery: mailbox first when
     * creating, database first when deleting.
     *
     * @template T
     *
     * @param callable(Connection): T $work
     *
     * @return T
     *
     * @throws PersistenceError
     * @throws Throwable
     */
    protected function transactional(callable $work): mixed
    {
        return $this->connection->transactional($work);
    }

    /**
     * @throws PersistenceError
     */
    private function countMatching(ListQuery $query, Filters $filters): int
    {
        $where = self::whereClause($query, $filters);
        $bindings = [...$query->bindings, ...$filters->bindings];

        $sql = $query->groupBy === null
            ? sprintf('SELECT COUNT(*) FROM %s%s', $query->from, $where)
            : sprintf(
                'SELECT COUNT(*) FROM (SELECT 1 FROM %s%s GROUP BY %s) AS %scount',
                $query->from,
                $where,
                $query->groupBy,
                FilterAllowlist::PLACEHOLDER_PREFIX,
            );

        /** @var mixed $total */
        $total = $this->connection->selectValue($sql, $bindings);

        return is_numeric($total) ? (int) $total : 0;
    }

    /**
     * A repository's own binding must not have the same name as one the base
     * generates. An exact-name check rather than a reserved prefix: the base
     * itself adds `ma_scope_*` through {@see restrictToDomains()}, and a prefix
     * rule would have to forbid its own work.
     *
     * @throws CriteriaError
     */
    private static function assertNoBindingCollision(ListQuery $query, Filters $filters): void
    {
        $generated = [...array_keys($filters->bindings), self::LIMIT, self::OFFSET];
        $collisions = array_values(array_intersect(array_keys($query->bindings), $generated));

        if ($collisions !== []) {
            throw CriteriaError::bindingCollision($collisions);
        }
    }

    private static function whereClause(ListQuery $query, Filters $filters): string
    {
        $parts = array_values(array_filter([$query->where, $filters->sql]));

        return $parts === [] ? '' : ' WHERE ' . implode(' AND ', $parts);
    }

    /**
     * The handle, for the few operations that need the driver itself.
     *
     * @throws PersistenceError
     */
    protected function pdo(): PDO
    {
        return $this->connection->pdo();
    }
}
