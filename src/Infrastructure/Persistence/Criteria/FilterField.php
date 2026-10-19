<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence\Criteria;

/**
 * One entry of a {@see FilterAllowlist}: a contract query parameter, the column
 * it addresses and how it compares.
 */
final readonly class FilterField
{
    public ColumnName $column;

    /**
     * @param string $key    the query parameter as the contract spells it
     * @param string $column `column` or `alias.column`
     *
     * @throws CriteriaError when `$column` is not a plain column name
     */
    public function __construct(
        public string $key,
        string $column,
        public FilterComparison $comparison = FilterComparison::Equals,
    ) {
        $this->column = ColumnName::parse($key, $column);
    }
}
