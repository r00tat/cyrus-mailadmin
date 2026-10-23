<?php

declare(strict_types=1);

namespace Mailadmin\Domain\MailingList;

use Mailadmin\Domain\Exception\InvalidMailingList;

/**
 * The display name of a mailing list.
 *
 * The identity of a list is its address; `lists.name` is display text and may
 * repeat (spec 4.3, "Identität"). Since the pipe transport, no aliases file is
 * keyed by it, so spaces, colons and umlauts are as welcome here as in any
 * other label.
 *
 * Two doors, the same shape as {@see \Mailadmin\Domain\Model\LocalPart}:
 * {@see fromString()} for a name somebody is entering, {@see fromStoredValue()}
 * for every string a row can hold, which it only marks {@see isConventional()}
 * `=== false` (U-22) — one odd row must neither disappear nor make a listing fail.
 *
 * ## What the strict door refuses, and why
 *
 * Only what cannot be stored as written:
 *
 *  - an empty name, after trimming;
 *  - more than 255 characters — `lists.name` is a latin1 `varchar(255)`, so
 *    the limit is in characters and every accepted character is one byte there;
 *  - control characters (below U+0020, and U+007F), which no label needs and
 *    which make a row look different in every tool that shows it;
 *  - characters outside printable latin1 (U+0020–U+007E, U+00A0–U+00FF).
 *
 * The last rule is the column, not taste. The connection speaks utf8mb4 and
 * MariaDB converts on write: an umlaut arrives intact, but a character latin1
 * cannot hold makes a strict-mode server refuse the statement (error 1366, an
 * opaque `500`) and a non-strict one store `?` — neither is what the form said,
 * so the form is told instead (`list.name_outside_latin1`). The C1 controls
 * (U+0080–U+009F) are refused with them: the server turns them into `?` too.
 * MariaDB's latin1 is in fact cp1252 and would store `€`, but the other tools
 * reading `lists` are not known to agree on that, so ISO 8859-1 is the bound.
 */
final readonly class MailingListName
{
    /** `lists.name` is a latin1 `varchar(255)`: 255 characters. */
    public const int MAX_LENGTH = 255;

    /** Printable ISO 8859-1 — what the latin1 column holds without loss. */
    private const string PRINTABLE_LATIN1 = '/^[\x{20}-\x{7E}\x{A0}-\x{FF}]*$/u';

    /** C0 controls and DEL. */
    private const string CONTROL = '/[\x00-\x1F\x7F]/';

    private function __construct(
        private string $value,
        private bool $conventional,
    ) {
    }

    /**
     * The strict door: trimmed, 1–255 characters, no control characters,
     * nothing latin1 cannot store.
     *
     * @throws InvalidMailingList
     */
    public static function fromString(string $value): self
    {
        $name = trim($value);

        if ($name === '') {
            throw InvalidMailingList::emptyName();
        }

        if (preg_match(self::CONTROL, $name) === 1) {
            throw InvalidMailingList::nameHasForbiddenCharacter($name, 'it contains a control character');
        }

        // Also false for a string that is not UTF-8 at all.
        if (preg_match(self::PRINTABLE_LATIN1, $name) !== 1) {
            throw InvalidMailingList::nameOutsideLatin1($name);
        }

        $length = self::characters($name);

        if ($length > self::MAX_LENGTH) {
            throw InvalidMailingList::nameTooLong($length, self::MAX_LENGTH);
        }

        return new self($name, true);
    }

    /**
     * The tolerant door: for a name that is already in the table. Nothing is
     * refused, trimmed or changed, so a row saved unchanged keeps its bytes.
     */
    public static function fromStoredValue(string $value): self
    {
        return new self($value, self::isConventionalValue($value));
    }

    public function toString(): string
    {
        return $this->value;
    }

    /**
     * False for a stored name the strict door would not hand back unchanged:
     * one it would refuse, and one with surrounding whitespace it would trim.
     */
    public function isConventional(): bool
    {
        return $this->conventional;
    }

    /**
     * Case-sensitive, because MariaDB compares the column under the table's
     * collation — which the application does not control. An `equals()` that
     * lower-cased would claim two names are one when the database might disagree.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    private static function isConventionalValue(string $value): bool
    {
        try {
            return self::fromString($value)->value === $value;
        } catch (InvalidMailingList) {
            return false;
        }
    }

    /** Characters, not bytes; the caller has established valid UTF-8. */
    private static function characters(string $value): int
    {
        return (int) preg_match_all('/./su', $value);
    }
}
