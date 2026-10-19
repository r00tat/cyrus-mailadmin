<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence\Criteria;

/**
 * The `order` query parameter (U-20). Two values, and the SQL keyword for each
 * comes from here rather than from the request.
 */
enum SortDirection: string
{
    case Ascending = 'asc';
    case Descending = 'desc';

    public static function fromRequest(?string $value): self
    {
        if ($value === null || trim($value) === '') {
            return self::Ascending;
        }

        return self::tryFrom(strtolower(trim($value)))
            ?? throw CriteriaError::unknownOrder($value);
    }

    public function keyword(): string
    {
        return $this === self::Ascending ? 'ASC' : 'DESC';
    }
}
