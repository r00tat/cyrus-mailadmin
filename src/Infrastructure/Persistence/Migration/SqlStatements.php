<?php

declare(strict_types=1);

namespace Mailadmin\Infrastructure\Persistence\Migration;

/**
 * Splits a migration file into statements, and strips the comments while doing
 * it.
 *
 * A migration is plain SQL (F3, open point 2 — additive changes need no logic),
 * so something has to find the statement boundaries. Splitting on `;` alone is
 * wrong for a semicolon inside a string literal or a comment, and both occur in
 * real DDL — a `COMMENT 'holds a; separated list'` is enough.
 *
 * The scanner therefore walks the file character by character and tracks four
 * states: single-quoted string, double-quoted string, back-quoted identifier,
 * and comment (`--` to end of line, `#` to end of line, `/* … *&#47;`). MySQL's
 * backslash escape inside strings is honoured; the doubled-quote escape as well.
 *
 * What it deliberately does **not** support is `DELIMITER`. Changing the
 * statement separator is only needed for a trigger, a procedure or a function,
 * and none of those belong in an additive migration — a stored routine would be
 * a fourth writer on a database that already has three. {@see AdditiveOnlyRule}
 * rejects them by name, and this class does not need to parse them.
 *
 * The comments are removed rather than kept because the rule check reads the
 * statements afterwards, and a rule that can be switched off by writing
 * `/* DROP TABLE domain *&#47;` next to something else would be no rule.
 */
final class SqlStatements
{
    private function __construct()
    {
    }

    /**
     * @return list<string> non-empty statements, comments removed, in file order
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $index = 0;

        while ($index < $length) {
            $character = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';

            // ---- comments ------------------------------------------------
            if ($character === '-' && $next === '-') {
                $index = self::skipToEndOfLine($sql, $index);
                $current .= ' ';

                continue;
            }

            if ($character === '#') {
                $index = self::skipToEndOfLine($sql, $index);
                $current .= ' ';

                continue;
            }

            if ($character === '/' && $next === '*') {
                $end = strpos($sql, '*/', $index + 2);
                $index = $end === false ? $length : $end + 2;
                $current .= ' ';

                continue;
            }

            // ---- quoted runs ---------------------------------------------
            if ($character === "'" || $character === '"' || $character === '`') {
                [$literal, $index] = self::readQuoted($sql, $index, $character);
                $current .= $literal;

                continue;
            }

            // ---- statement boundary --------------------------------------
            if ($character === ';') {
                $statements[] = $current;
                $current = '';
                ++$index;

                continue;
            }

            $current .= $character;
            ++$index;
        }

        $statements[] = $current;

        return array_values(array_filter(
            array_map(static fn (string $statement): string => trim($statement), $statements),
            static fn (string $statement): bool => $statement !== '',
        ));
    }

    private static function skipToEndOfLine(string $sql, int $index): int
    {
        $newline = strpos($sql, "\n", $index);

        return $newline === false ? strlen($sql) : $newline + 1;
    }

    /**
     * @return array{string, int} the literal including its delimiters, and the index after it
     */
    private static function readQuoted(string $sql, int $index, string $delimiter): array
    {
        $length = strlen($sql);
        $literal = $delimiter;
        ++$index;

        while ($index < $length) {
            $character = $sql[$index];

            // Backslash escapes apply to strings, not to back-quoted identifiers.
            if ($character === '\\' && $delimiter !== '`' && $index + 1 < $length) {
                $literal .= $character . $sql[$index + 1];
                $index += 2;

                continue;
            }

            if ($character === $delimiter) {
                // A doubled delimiter is the delimiter itself, not the end.
                if ($index + 1 < $length && $sql[$index + 1] === $delimiter) {
                    $literal .= $delimiter . $delimiter;
                    $index += 2;

                    continue;
                }

                return [$literal . $delimiter, $index + 1];
            }

            $literal .= $character;
            ++$index;
        }

        // Unterminated. Returned as-is; the database will complain with a better
        // message than a parser written for a lint check could.
        return [$literal, $index];
    }
}
