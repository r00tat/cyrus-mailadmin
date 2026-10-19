<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence\Criteria;

/**
 * What a repository is willing to sort by — the answer to `CLAUDE.md`, rule 2.
 *
 * The old system built its `ORDER BY` from a value it had kept in the session
 * and pasted it into the SQL. This class is the reason that cannot happen here:
 * a repository declares the fields it offers, the request may only name one of
 * them, and the only object that can produce an `ORDER BY` fragment is
 * {@see OrderBy}, whose constructor is private and whose single factory takes
 * an allowlist. There is no path from a request string to a SQL fragment that
 * does not pass through this class.
 *
 * The keys are the values of the `sort` enum in the contract (U-20); the values
 * are column names, validated by {@see ColumnName}.
 *
 * **The tiebreaker matters more than it looks.** Sorting a page by a field with
 * duplicates leaves the order of equal rows to the server, so a row can appear
 * on page 1 and again on page 2 while nothing changes. Every allowlist
 * therefore carries a unique field that is appended to every ordering.
 */
final readonly class SortAllowlist
{
    /** @var non-empty-array<string, ColumnName> */
    private array $fields;

    /**
     * @param non-empty-array<string, string> $fields contract sort key => column or alias.column
     * @param string                          $default the key used when the request names none
     * @param string|null                     $tiebreaker a unique key appended to every ordering;
     *                                                    defaults to `$default`
     *
     * @throws CriteriaError when the list is empty, the default is not in it, or a value is not
     *                       a plain column name
     */
    public function __construct(
        array $fields,
        private string $default,
        private ?string $tiebreaker = null,
    ) {
        if ($fields === []) {
            throw CriteriaError::emptyAllowlist();
        }

        $parsed = [];
        foreach ($fields as $key => $column) {
            $parsed[$key] = ColumnName::parse($key, $column);
        }

        /** @var non-empty-array<string, ColumnName> $parsed */
        $this->fields = $parsed;

        if (!isset($this->fields[$default])) {
            throw CriteriaError::defaultNotAllowed($default);
        }

        if ($tiebreaker !== null && !isset($this->fields[$tiebreaker])) {
            throw CriteriaError::defaultNotAllowed($tiebreaker);
        }
    }

    /**
     * @return list<string> the keys, in declaration order — what the contract `enum` must list
     */
    public function keys(): array
    {
        return array_keys($this->fields);
    }

    public function has(string $key): bool
    {
        return isset($this->fields[$key]);
    }

    public function defaultKey(): string
    {
        return $this->default;
    }

    public function tiebreakerKey(): string
    {
        return $this->tiebreaker ?? $this->default;
    }

    /**
     * @throws CriteriaError when the key is not in the list
     */
    public function column(string $key): ColumnName
    {
        return $this->fields[$key] ?? throw CriteriaError::unknownSortField($key, $this->keys());
    }

    /**
     * Resolve what the request asked for. `null` and `''` mean "the default".
     *
     * @throws CriteriaError when the request names a field that is not offered
     */
    public function resolve(?string $requested): ColumnName
    {
        $key = $requested === null || trim($requested) === '' ? $this->default : trim($requested);

        return $this->column($key);
    }
}
