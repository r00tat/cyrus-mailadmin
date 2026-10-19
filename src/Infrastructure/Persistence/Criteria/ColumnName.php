<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence\Criteria;

/**
 * A column reference that has been proved to be nothing but a column reference.
 *
 * The allowlists accept `column` or `alias.column`, nothing else — no function
 * call, no expression, no second identifier. It is a deliberate restriction on
 * *our own* code: the value in an allowlist is written by a developer, not by a
 * user, but the whole point of the allowlist is that the composed fragment can
 * only ever contain an identifier. Validating the developer's side too means
 * that claim does not rest on review.
 *
 * If a listing needs an expression to sort by, it selects it under an alias and
 * puts the alias here. MySQL orders by a select alias, and the alias is an
 * identifier again.
 */
final readonly class ColumnName
{
    private const string PART = '[A-Za-z_][A-Za-z0-9_]*';

    private function __construct(public string $value)
    {
    }

    /**
     * @param string $key the allowlist key, named in the error message
     *
     * @throws CriteriaError when `$column` is anything other than an identifier
     */
    public static function parse(string $key, string $column): self
    {
        $trimmed = trim($column);

        if (preg_match('/^' . self::PART . '(\.' . self::PART . ')?$/', $trimmed) !== 1) {
            throw CriteriaError::notAnIdentifier($key, $column);
        }

        return new self($trimmed);
    }

    /**
     * Back-quoted, part by part. Safe because {@see parse()} already proved that
     * every part is `[A-Za-z_][A-Za-z0-9_]*` — there is no backtick to escape.
     */
    public function quoted(): string
    {
        return implode('.', array_map(
            static fn (string $part): string => '`' . $part . '`',
            explode('.', $this->value),
        ));
    }
}
