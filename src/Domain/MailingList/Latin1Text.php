<?php

declare(strict_types=1);

namespace Mailadmin\Domain\MailingList;

/**
 * What the latin1 columns of `lists` can hold without loss.
 *
 * `lists` is a latin1 table and the connection speaks utf8mb4, so MariaDB
 * converts on every write. An umlaut arrives intact. A character latin1 cannot
 * hold makes a strict-mode server refuse the statement (error 1366, which
 * would surface as an opaque `500`) and a non-strict one store `?` — neither is
 * what the form said. Name, tag and members therefore refuse such text on the
 * way in, each with its own code, and this is the one place that decides what
 * "such text" is.
 *
 * The bound is ISO 8859-1 without the C1 controls: U+0000–U+007F and
 * U+00A0–U+00FF. The server turns the C1 controls into `?` as well. MariaDB's
 * latin1 is in fact cp1252 and would store `€`, but the other tools reading
 * `lists` are not known to agree on that.
 *
 * C0 controls are inside the bound on purpose: the column stores them, and
 * whether a field wants them is that field's own rule.
 *
 * Only for **new or changed** values. What is already stored is read as it is
 * (U-22).
 */
final class Latin1Text
{
    private const string FITS = '/^[\x{00}-\x{7F}\x{A0}-\x{FF}]*$/u';

    private function __construct()
    {
    }

    /** False as well for a string that is not UTF-8 at all. */
    public static function fits(string $text): bool
    {
        return preg_match(self::FITS, $text) === 1;
    }

    /**
     * Characters, not bytes — the unit a latin1 `varchar(n)` counts in, where
     * every character that {@see fits()} is one byte. Expects valid UTF-8.
     */
    public static function characters(string $text): int
    {
        return (int) preg_match_all('/./su', $text);
    }
}
