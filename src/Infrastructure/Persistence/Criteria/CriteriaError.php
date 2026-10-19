<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence\Criteria;

use Mailadmin\Infrastructure\Persistence\ErrorCode;
use Mailadmin\Infrastructure\Persistence\PersistenceError;

/**
 * A sort field, filter field or page value that the repository does not allow.
 *
 * It carries `request.validation_failed`, because that is what it is: the
 * request asked for something the contract does not offer. The contract should
 * have rejected it first — every `sort` parameter is an `enum` in the schema
 * (U-20) — so reaching this class means the schema and the repository disagree.
 * **That is why it is an error and not a silent fallback to the default sort.**
 * The old system took the other route: it interpolated the ordering from the
 * session straight into the SQL, and nothing ever told it that the value was
 * unexpected.
 */
final class CriteriaError extends PersistenceError
{
    /**
     * @param list<string> $allowed
     */
    public static function unknownSortField(string $requested, array $allowed): self
    {
        return new self(ErrorCode::CRITERIA_INVALID, sprintf(
            'Cannot sort by "%s". This repository allows: %s. The contract enum and the '
            . 'repository allowlist have drifted apart.',
            $requested,
            implode(', ', $allowed),
        ));
    }

    /**
     * @param list<string> $allowed
     */
    public static function unknownFilterField(string $requested, array $allowed): self
    {
        return new self(ErrorCode::CRITERIA_INVALID, sprintf(
            'Cannot filter by "%s". This repository allows: %s.',
            $requested,
            $allowed === [] ? '<nothing>' : implode(', ', $allowed),
        ));
    }

    public static function unknownOrder(string $requested): self
    {
        return new self(ErrorCode::CRITERIA_INVALID, sprintf(
            'Sort order must be "asc" or "desc", got "%s".',
            $requested,
        ));
    }

    public static function pageOutOfRange(int $page): self
    {
        return new self(ErrorCode::CRITERIA_INVALID, sprintf(
            'Page numbers start at 1 (U-20), got %d.',
            $page,
        ));
    }

    public static function perPageOutOfRange(int $perPage, int $maximum): self
    {
        return new self(ErrorCode::CRITERIA_INVALID, sprintf(
            'per_page must be between 1 and %d (U-20), got %d.',
            $maximum,
            $perPage,
        ));
    }

    /**
     * Not a request problem but a programming one: an allowlist that names
     * something other than a column.
     */
    public static function notAnIdentifier(string $key, string $column): self
    {
        return new self(ErrorCode::CRITERIA_INVALID, sprintf(
            'The allowlist entry "%s" maps to "%s", which is not a plain column name. Only '
            . '`column` or `alias.column` are accepted, so that the composed fragment cannot '
            . 'contain anything but an identifier.',
            $key,
            $column,
        ));
    }

    public static function emptyAllowlist(): self
    {
        return new self(
            ErrorCode::CRITERIA_INVALID,
            'A sort allowlist must name at least one field: every listing needs a defined order, '
            . 'otherwise page 2 may repeat rows from page 1.',
        );
    }

    public static function defaultNotAllowed(string $default): self
    {
        return new self(ErrorCode::CRITERIA_INVALID, sprintf(
            'The default sort field "%s" is not part of the allowlist it belongs to.',
            $default,
        ));
    }

    /**
     * @param list<string> $names
     */
    public static function bindingCollision(array $names): self
    {
        return new self(ErrorCode::CRITERIA_INVALID, sprintf(
            'The query binds :%s, which the repository base also binds for the paging and filter '
            . 'fragments it composes itself. One of the two values would be overwritten silently, '
            . 'and a scope restriction that quietly loses its value stops restricting anything.',
            implode(', :', $names),
        ));
    }

    public static function filterValueNotScalar(string $key): self
    {
        return new self(ErrorCode::CRITERIA_INVALID, sprintf(
            'The filter "%s" was given a value that is neither a scalar nor a list of scalars.',
            $key,
        ));
    }

    public static function emptyInFilter(string $key): self
    {
        return new self(ErrorCode::CRITERIA_INVALID, sprintf(
            'The filter "%s" expects a non-empty list. An empty "IN ()" is a syntax error in SQL '
            . 'and an empty result in intent — say which one you mean.',
            $key,
        ));
    }
}
