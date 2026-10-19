<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

/**
 * The static part of a listing: what to select, from where, and which
 * predicates always apply.
 *
 * Everything in here is written by the repository as a literal. What comes from
 * the request — ordering, page, filters — is *not* part of this object;
 * {@see Repository::fetchPage()} adds those from the allowlists. Splitting it
 * this way is what allows the base to build the `COUNT(*)` query itself: it has
 * the `FROM` and the `WHERE` without the `ORDER BY` and the `LIMIT`.
 *
 * `$bindings` are for the fixed predicates — a domain scope, a status flag. They
 * are named, and {@see Repository::fetchPage()} refuses to run a query whose
 * bindings collide with the ones it generates itself: a silently overwritten
 * value would turn a scope restriction into no restriction.
 */
final readonly class ListQuery
{
    /**
     * @param string                     $select   column list, without the `SELECT` keyword
     * @param string                     $from     table and joins, without the `FROM` keyword
     * @param string                     $where    fixed predicates, without the `WHERE` keyword
     * @param array<string, scalar|null> $bindings named bindings for `$where` and `$from`
     * @param string|null                $groupBy  grouping, without the `GROUP BY` keyword
     */
    public function __construct(
        public string $select,
        public string $from,
        public string $where = '',
        public array $bindings = [],
        public ?string $groupBy = null,
    ) {
    }

    /**
     * The same query with one more fixed predicate, `AND`-joined.
     *
     * @param array<string, scalar|null> $bindings
     */
    public function andWhere(string $predicate, array $bindings = []): self
    {
        return new self(
            $this->select,
            $this->from,
            $this->where === '' ? $predicate : '(' . $this->where . ') AND (' . $predicate . ')',
            [...$this->bindings, ...$bindings],
            $this->groupBy,
        );
    }
}
