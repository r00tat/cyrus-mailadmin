<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence\Criteria;

/**
 * What a repository is willing to filter by — the same rule as
 * {@see SortAllowlist}, applied to `WHERE`.
 *
 * A filter key that is not declared is an error, never a silently ignored
 * parameter. Ignoring it would mean a request that asks for "only the accounts
 * with a legacy hash" quietly gets all of them, and the operator has no way to
 * tell.
 */
final readonly class FilterAllowlist
{
    /** Placeholders this class generates. A repository may not use the prefix. */
    public const string PLACEHOLDER_PREFIX = 'ma_';

    /** @var array<string, FilterField> */
    private array $fields;

    public function __construct(FilterField ...$fields)
    {
        $indexed = [];
        foreach ($fields as $field) {
            $indexed[$field->key] = $field;
        }

        $this->fields = $indexed;
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->fields);
    }

    public function has(string $key): bool
    {
        return isset($this->fields[$key]);
    }

    /**
     * Turn the request's filters into predicates.
     *
     * @param array<string, scalar|list<scalar>> $requested
     *
     * @throws CriteriaError when a key is not declared or a value has the wrong shape
     */
    public function compile(array $requested): Filters
    {
        if ($requested === []) {
            return Filters::none();
        }

        $predicates = [];
        $bindings = [];
        $counter = 0;

        foreach ($requested as $key => $value) {
            $field = $this->fields[$key]
                ?? throw CriteriaError::unknownFilterField($key, $this->keys());

            $placeholder = self::PLACEHOLDER_PREFIX . 'f' . $counter++;

            if ($field->comparison->expectsList()) {
                $predicates[] = self::inPredicate($field, $value, $placeholder, $bindings);

                continue;
            }

            if (is_array($value)) {
                throw CriteriaError::filterValueNotScalar($key);
            }

            [$predicates[], $bindings[$placeholder]] = self::scalarPredicate(
                $field,
                $value,
                $placeholder,
            );
        }

        return Filters::of($predicates, $bindings);
    }

    /**
     * The character that marks an escaped wildcard inside a `LIKE` pattern.
     *
     * **Not the backslash, and that is the whole point.** A backslash cannot be
     * written into this clause portably: MariaDB reads `'\'` as an escaped
     * closing quote and answers with a syntax error, while SQLite — which the
     * repository unit tests run against — takes the doubled `'\\'` literally and
     * refuses it as two characters. There is no spelling of a backslash that
     * both accept, so the escape character is one that needs no escaping in
     * either dialect.
     *
     * `!` is not special to `LIKE`, to either dialect's string literals, or to
     * this application's data. Where it does occur in a filter value,
     * {@see escapeLike()} doubles it like any other escapable character.
     */
    private const string LIKE_ESCAPE_CHARACTER = '!';

    /** The clause itself, appended to every `LIKE` this class composes. */
    private const string LIKE_ESCAPE = " ESCAPE '" . self::LIKE_ESCAPE_CHARACTER . "'";

    /**
     * @return array{string, scalar|null}
     */
    private static function scalarPredicate(
        FilterField $field,
        string|int|float|bool $value,
        string $placeholder,
    ): array {
        $column = $field->column->quoted();

        return match ($field->comparison) {
            FilterComparison::Equals => [$column . ' = :' . $placeholder, $value],
            FilterComparison::NotEquals => [$column . ' <> :' . $placeholder, $value],
            FilterComparison::GreaterOrEqual => [$column . ' >= :' . $placeholder, $value],
            FilterComparison::LessOrEqual => [$column . ' <= :' . $placeholder, $value],
            FilterComparison::Contains => [
                $column . ' LIKE :' . $placeholder . self::LIKE_ESCAPE,
                '%' . self::escapeLike((string) $value) . '%',
            ],
            FilterComparison::StartsWith => [
                $column . ' LIKE :' . $placeholder . self::LIKE_ESCAPE,
                self::escapeLike((string) $value) . '%',
            ],
            FilterComparison::In => throw CriteriaError::filterValueNotScalar($field->key),
        };
    }

    /**
     * @param scalar|list<scalar>        $value
     * @param array<string, scalar|null> $bindings
     *
     * @param-out array<string, scalar|null> $bindings
     */
    private static function inPredicate(
        FilterField $field,
        string|int|float|bool|array $value,
        string $placeholder,
        array &$bindings,
    ): string {
        $values = is_array($value) ? array_values($value) : [$value];

        if ($values === []) {
            throw CriteriaError::emptyInFilter($field->key);
        }

        $placeholders = [];
        foreach ($values as $index => $item) {
            $name = $placeholder . '_' . $index;
            $placeholders[] = ':' . $name;
            $bindings[$name] = $item;
        }

        return $field->column->quoted() . ' IN (' . implode(', ', $placeholders) . ')';
    }

    /**
     * Neutralise the two `LIKE` wildcards in a value the user typed, so that a
     * search for `a_b` finds `a_b` and not `axb`.
     *
     * The escape character is escaped **first**. Doing it last would double the
     * ones this method had just inserted, and the pattern would then look for
     * literal `!` characters that are not in the data.
     */
    private static function escapeLike(string $value): string
    {
        $escape = self::LIKE_ESCAPE_CHARACTER;

        return str_replace(
            [$escape, '%', '_'],
            [$escape . $escape, $escape . '%', $escape . '_'],
            $value,
        );
    }
}
