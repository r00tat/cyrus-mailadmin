<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence\Criteria;

/**
 * What a list request asked for: page, page size, sort field, direction,
 * filters (U-20).
 *
 * The values are validated here, even though the contract validates them too
 * (`page` has `minimum: 1`, `per_page` has `maximum: 200`). The duplication is
 * intentional: a use case may build a criteria object without an HTTP request
 * in sight — a maintenance command, a test — and "the schema already checked"
 * is not a property this class can rely on.
 *
 * `sort` stays a plain string. It is meaningless until a
 * {@see SortAllowlist} resolves it, and giving it a type here would suggest it
 * had already been checked.
 */
final readonly class ListCriteria
{
    public const int DEFAULT_PER_PAGE = 50;
    public const int MAX_PER_PAGE = 200;

    /**
     * @param array<string, scalar|list<scalar>> $filters
     *
     * @throws CriteriaError when page or per_page are outside their range
     */
    public function __construct(
        public int $page = 1,
        public int $perPage = self::DEFAULT_PER_PAGE,
        public ?string $sort = null,
        public SortDirection $order = SortDirection::Ascending,
        public array $filters = [],
    ) {
        if ($page < 1) {
            throw CriteriaError::pageOutOfRange($page);
        }

        if ($perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw CriteriaError::perPageOutOfRange($perPage, self::MAX_PER_PAGE);
        }
    }

    /**
     * Build from raw query parameters, as PSR-7 hands them over: everything is
     * a string, everything may be absent.
     *
     * `page` and `per_page` are consumed; every other key becomes a filter, to
     * be checked against the repository's {@see FilterAllowlist}. An unknown one
     * is therefore rejected there rather than here — this class does not know
     * what a given resource offers.
     *
     * @param array<string, mixed> $query
     *
     * @throws CriteriaError
     */
    public static function fromQuery(array $query): self
    {
        $filters = [];
        foreach ($query as $key => $value) {
            if (in_array($key, ['page', 'per_page', 'sort', 'order'], true)) {
                continue;
            }

            if (is_array($value)) {
                $filters[$key] = array_values(array_filter($value, 'is_scalar'));

                continue;
            }

            if (is_scalar($value)) {
                $filters[$key] = $value;
            }
        }

        /** @var array<string, scalar|list<scalar>> $filters */
        return new self(
            page: self::integer($query['page'] ?? null, 1),
            perPage: self::integer($query['per_page'] ?? null, self::DEFAULT_PER_PAGE),
            sort: self::text($query['sort'] ?? null),
            order: SortDirection::fromRequest(self::text($query['order'] ?? null)),
            filters: $filters,
        );
    }

    /**
     * The `OFFSET` for this page. Never negative — the constructor saw to that.
     */
    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function withSort(?string $sort, SortDirection $order): self
    {
        return new self($this->page, $this->perPage, $sort, $order, $this->filters);
    }

    /**
     * Add a filter the request did not ask for — a scope restriction, for
     * instance. It still has to be in the repository's allowlist.
     *
     * @param scalar|list<scalar> $value
     */
    public function withFilter(string $key, string|int|float|bool|array $value): self
    {
        return new self(
            $this->page,
            $this->perPage,
            $this->sort,
            $this->order,
            [...$this->filters, $key => $value],
        );
    }

    private static function integer(mixed $value, int $fallback): int
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        // Not a number at all. Reported as a range error rather than guessed at:
        // silently paging from 1 would hide a broken client.
        throw CriteriaError::pageOutOfRange(0);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
