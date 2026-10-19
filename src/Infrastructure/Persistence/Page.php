<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence;

use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;

/**
 * One page of results plus the `meta` block the contract requires (U-20).
 *
 * `total` counts everything the filters match, not what is in `items` — that is
 * what makes a page indicator possible. It costs a second query, which is the
 * price of `meta.total`; the alternative (cursor paging, no total) is a
 * contract change, not an implementation detail.
 *
 * @template T
 */
final readonly class Page
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }

    /**
     * @template U
     *
     * @param list<U> $items
     *
     * @return self<U>
     */
    public static function of(array $items, ListCriteria $criteria, int $total): self
    {
        return new self($items, $criteria->page, $criteria->perPage, $total);
    }

    /**
     * @return self<never>
     */
    public static function empty(ListCriteria $criteria): self
    {
        return new self([], $criteria->page, $criteria->perPage, 0);
    }

    /**
     * Exactly the shape of `PaginationMeta` in the contract.
     *
     * @return array{page: int, per_page: int, total: int}
     */
    public function meta(): array
    {
        return [
            'page' => $this->page,
            'per_page' => $this->perPage,
            'total' => $this->total,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function pageCount(): int
    {
        return $this->total === 0 ? 0 : (int) ceil($this->total / $this->perPage);
    }

    /**
     * Map the rows without losing the page information.
     *
     * @template U
     *
     * @param callable(T): U $mapper
     *
     * @return self<U>
     */
    public function map(callable $mapper): self
    {
        return new self(array_map($mapper, $this->items), $this->page, $this->perPage, $this->total);
    }
}
