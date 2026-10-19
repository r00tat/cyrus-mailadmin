<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence\Criteria;

/**
 * How a filter compares. The set is closed on purpose: a repository picks one,
 * it does not write a predicate.
 *
 * `Contains` and `StartsWith` escape `%`, `_` and the escape character itself
 * before binding, and emit an explicit `ESCAPE '\'`. Without the escaping a
 * search for `a_b` would also match `axb`; without the explicit `ESCAPE` the
 * behaviour differs between MySQL (backslash by default) and SQLite (no default
 * at all), and the unit tests run against the latter.
 */
enum FilterComparison
{
    case Equals;
    case NotEquals;
    case GreaterOrEqual;
    case LessOrEqual;
    case Contains;
    case StartsWith;

    /**
     * Values are matched against a list. The bound values are one placeholder
     * each — `IN (?, ?, ?)` — never a joined string.
     */
    case In;

    public function expectsList(): bool
    {
        return $this === self::In;
    }
}
