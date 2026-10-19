<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence\Criteria;

/**
 * A finished `ORDER BY` fragment.
 *
 * **The constructor is private and there is exactly one factory, which demands
 * a {@see SortAllowlist}.** That is the whole design: no object of this type can
 * exist that was not checked against an allowlist, so a repository cannot hand
 * the query builder an ordering it made up from a request value — it has no way
 * to construct one.
 *
 * The fragment always ends with the allowlist's tiebreaker, so that pagination
 * is stable when the chosen field has duplicates.
 */
final readonly class OrderBy
{
    /**
     * @param non-empty-list<array{ColumnName, SortDirection}> $terms
     */
    private function __construct(private array $terms)
    {
    }

    /**
     * @throws CriteriaError when `$requested` is not in the allowlist
     */
    public static function fromAllowlist(
        SortAllowlist $allowlist,
        ?string $requested,
        SortDirection $direction = SortDirection::Ascending,
    ): self {
        $primaryKey = $requested === null || trim($requested) === ''
            ? $allowlist->defaultKey()
            : trim($requested);

        $terms = [[$allowlist->column($primaryKey), $direction]];

        $tiebreaker = $allowlist->tiebreakerKey();
        if ($tiebreaker !== $primaryKey) {
            // The tiebreaker keeps its own direction: it exists to make the order
            // total, not to follow the field the user picked.
            $terms[] = [$allowlist->column($tiebreaker), SortDirection::Ascending];
        }

        return new self($terms);
    }

    /**
     * `ORDER BY `a`.`b` ASC, `c` ASC` — never empty.
     */
    public function toSql(): string
    {
        return 'ORDER BY ' . implode(', ', array_map(
            static fn (array $term): string => $term[0]->quoted() . ' ' . $term[1]->keyword(),
            $this->terms,
        ));
    }
}
