<?php

declare(strict_types=1);

namespace Mailadmin\Domain\MailingList;

use Mailadmin\Domain\Exception\InvalidMailingList;

/**
 * The primary key of `lists`, and therefore the identity of a mailing list.
 *
 * `lists` has no surrogate key — `name` is the primary key (analysis 4.1), so
 * it is what a URL addresses (U-60). Two doors, the same shape as
 * {@see \Mailadmin\Domain\Model\LocalPart}: {@see fromString()} is strict for a
 * name somebody is creating, {@see fromStoredValue()} is tolerant for a row that
 * is already there and only marks it {@see isConventional()} `=== false` (U-22).
 *
 * The strict rules are not decoration. The name ends up as the key of an entry
 * in an aliases file, where whitespace and `:` are structure; a name carrying
 * either would produce a file the MTA reads differently from what the form said.
 * Both doors therefore refuse those outright, and the strict door additionally
 * refuses anything that is not a plain label.
 */
final readonly class MailingListName
{
    /** `lists.name` is a `varchar(255)`. */
    public const int MAX_LENGTH = 255;

    private function __construct(
        private string $value,
        private bool $conventional,
    ) {
    }

    /**
     * The strict door: letters, digits, `.`, `-`, `_`, `+`, beginning and ending
     * with a letter or a digit.
     *
     * @throws InvalidMailingList
     */
    public static function fromString(string $value): self
    {
        $name = self::guardUsable($value);

        if (!self::isConventionalValue($name)) {
            throw InvalidMailingList::unconventionalName($name);
        }

        return new self($name, true);
    }

    /**
     * The tolerant door: for a name that is already in the table.
     *
     * @throws InvalidMailingList
     */
    public static function fromStoredValue(string $value): self
    {
        $name = self::guardUsable($value);

        return new self($name, self::isConventionalValue($name));
    }

    public function toString(): string
    {
        return $this->value;
    }

    /**
     * False for a stored name this application would not accept from a form.
     */
    public function isConventional(): bool
    {
        return $this->conventional;
    }

    /**
     * Case-sensitive, because the column is the primary key and MariaDB compares
     * it under the table's collation — which the application does not control.
     * An `equals()` that lower-cased would claim two names are one when the
     * database might disagree.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * @throws InvalidMailingList
     */
    private static function guardUsable(string $value): string
    {
        $name = trim($value);

        if ($name === '') {
            throw InvalidMailingList::emptyName();
        }

        if (strlen($name) > self::MAX_LENGTH) {
            throw InvalidMailingList::nameTooLong(strlen($name), self::MAX_LENGTH);
        }

        if (preg_match('/[\s\x00-\x1F\x7F]/', $name) === 1) {
            throw InvalidMailingList::nameHasForbiddenCharacter(
                $name,
                'it contains whitespace or a control character, both of which are structure in an aliases file',
            );
        }

        if (str_contains($name, ':')) {
            throw InvalidMailingList::nameHasForbiddenCharacter(
                $name,
                'it contains ":", which separates the key from the members in an aliases file',
            );
        }

        return $name;
    }

    private static function isConventionalValue(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9._+-]*[A-Za-z0-9])?$/', $value) === 1;
    }
}
